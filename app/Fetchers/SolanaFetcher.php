<?php

namespace BCC\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Contracts\FetcherInterface;
use BCC\Onchain\Support\ApiRetry;

/**
 * Solana Chain Fetcher
 *
 * Supports:
 *  - Validators via getVoteAccounts RPC method
 *  - NFT collections via getAssetsByOwner DAS API
 */
class SolanaFetcher implements FetcherInterface
{
    private const HTTP_TIMEOUT = 30;
    private const SOLANA_RPC   = 'https://api.mainnet-beta.solana.com';

    private object $chain;
    private string $rpc_url;

    /** @var array|null Cached vote accounts for the current PHP process. */
    private static ?array $voteAccountsCache = null;

    public function __construct(object $chain)
    {
        $this->chain   = $chain;
        $this->rpc_url = $chain->rpc_url ?? self::SOLANA_RPC;
    }

    public function get_chain(): object
    {
        return $this->chain;
    }

    public function supports_feature(string $feature): bool
    {
        return in_array($feature, ['validator', 'collection', 'top_collections'], true);
    }

    // ══════════════════════════════════════════════════════════════════
    // VALIDATORS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Fetch a single validator by identity pubkey.
     */
    public function fetch_validator(string $address): array
    {
        $all = $this->getVoteAccounts();

        foreach ($all as $v) {
            if (($v['nodePubkey'] ?? '') === $address) {
                return $this->mapValidator($v, 0);
            }
        }

        return [];
    }

    /**
     * Fetch all active validators sorted by stake descending.
     */
    public function fetch_all_validators(): array
    {
        $accounts = $this->getVoteAccounts();

        if (empty($accounts)) {
            return [];
        }

        // Sort by activatedStake descending for rank.
        usort($accounts, function ($a, $b) {
            return bccomp($b['activatedStake'] ?? '0', $a['activatedStake'] ?? '0');
        });

        $results = [];
        foreach ($accounts as $rank => $v) {
            $results[] = $this->mapValidator($v, $rank);
        }

        return $results;
    }

    /**
     * Enrich a validator. All data comes in one getVoteAccounts call,
     * so this just re-fetches from the cached set.
     */
    public function enrich_validator(string $address, ?object $existingRow = null): array
    {
        return $this->fetch_validator($address);
    }

    // ══════════════════════════════════════════════════════════════════
    // COLLECTIONS (existing functionality)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Fetch NFT collections associated with a Solana wallet.
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array
    {
        $chainId = $chainId ?: (int) $this->chain->id;

        $assets = $this->rpcCall('getAssetsByOwner', [
            'ownerAddress'   => $walletAddress,
            'displayOptions' => ['showCollectionMetadata' => true],
            'limit'          => 500,
            'page'           => 1,
        ]);

        if (!is_array($assets)) {
            return [];
        }

        $collections = [];

        foreach ($assets as $asset) {
            $asset = (object) $asset;
            $grouping = $asset->grouping ?? [];

            $collectionAddr = null;
            foreach ($grouping as $g) {
                $g = (object) $g;
                if (($g->group_key ?? '') === 'collection' && !empty($g->group_value)) {
                    $collectionAddr = $g->group_value;
                    break;
                }
            }

            if (!$collectionAddr) {
                continue;
            }

            $key = strtolower($collectionAddr);

            if (!isset($collections[$key])) {
                $collMeta = null;
                foreach ($grouping as $g) {
                    $g = (object) $g;
                    if (($g->group_key ?? '') === 'collection' && isset($g->collection_metadata)) {
                        $collMeta = (object) $g->collection_metadata;
                        break;
                    }
                }

                $collections[$key] = [
                    'contract_address'   => $collectionAddr,
                    'collection_name'    => $collMeta->name ?? $asset->content->metadata->name ?? null,
                    'chain_id'           => $chainId,
                    'token_standard'     => 'Metaplex',
                    'total_supply'       => null,
                    'floor_price'        => null,
                    'floor_currency'     => 'SOL',
                    'total_volume'       => null,
                    'unique_holders'     => null,
                    'listed_percentage'  => null,
                    'royalty_percentage' => null,
                    'metadata_storage'   => null,
                    '_count'             => 0,
                ];

                if (isset($asset->royalty->percent)) {
                    $collections[$key]['royalty_percentage'] = round((float) $asset->royalty->percent * 100, 2);
                }

                $uri = $asset->content->json_uri ?? '';
                if (str_contains($uri, 'arweave.net')) {
                    $collections[$key]['metadata_storage'] = 'arweave';
                } elseif (str_contains($uri, 'ipfs') || str_contains($uri, 'nftstorage.link')) {
                    $collections[$key]['metadata_storage'] = 'ipfs';
                }
            }

            $collections[$key]['_count']++;
        }

        $result = [];
        foreach ($collections as $coll) {
            unset($coll['_count']);
            $result[] = $coll;
        }

        return $result;
    }

    // ══════════════════════════════════════════════════════════════════
    // TOP COLLECTIONS (Magic Eden API v2)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Fetch top Solana NFT collections via Magic Eden API v2.
     * Free endpoint, no API key required.
     *
     * @param int $limit Max collections to return (max 100).
     * @return array[] Normalized collection rows for bulkUpsert().
     */
    public function fetch_top_collections(int $limit = 100): array
    {
        $chainId = (int) $this->chain->id;

        $url = add_query_arg([
            'timeRange' => '7d',
            'limit'     => min($limit, 100),
        ], 'https://api-mainnet.magiceden.dev/v2/marketplace/popular_collections');

        $response = ApiRetry::get($url, [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ], [
            'label'    => 'Magic Eden top collections',
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[Solana Fetcher] Magic Eden fetch failed: ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            \BCC\Core\Log\Logger::error('[Solana Fetcher] Magic Eden returned ' . $code);
            return [];
        }

        $items = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($items)) {
            return [];
        }

        $collections = [];

        foreach ($items as $item) {
            $symbol = $item['symbol'] ?? '';
            if (!$symbol) {
                continue;
            }

            $floorLamports  = $item['floorPrice'] ?? null;
            $volumeLamports = $item['volumeAll'] ?? null;

            $collections[] = [
                'contract_address'   => $symbol,
                'chain_id'           => $chainId,
                'collection_name'    => $item['name'] ?? $symbol,
                'token_standard'     => 'Metaplex',
                'total_supply'       => isset($item['totalItems']) ? (int) $item['totalItems'] : null,
                'floor_price'        => $floorLamports !== null ? (float) $floorLamports / 1e9 : null,
                'floor_currency'     => 'SOL',
                'unique_holders'     => null,
                'total_volume'       => $volumeLamports !== null ? (float) $volumeLamports / 1e9 : null,
                'listed_percentage'  => isset($item['listedCount'], $item['totalItems']) && $item['totalItems'] > 0
                    ? round((int) $item['listedCount'] / (int) $item['totalItems'] * 100, 2)
                    : null,
                'royalty_percentage' => null,
                'metadata_storage'   => null,
                'image_url'          => $item['image'] ?? null,
            ];
        }

        return $collections;
    }

    // ══════════════════════════════════════════════════════════════════
    // INTERNAL
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get all vote accounts (cached per PHP process).
     */
    private function getVoteAccounts(): array
    {
        if (self::$voteAccountsCache !== null) {
            return self::$voteAccountsCache;
        }

        $result = $this->rpcCall('getVoteAccounts', []);

        if (!is_array($result)) {
            self::$voteAccountsCache = [];
            return [];
        }

        // Merge current (active) and delinquent (inactive) into one list.
        $current    = $result['current'] ?? [];
        $delinquent = $result['delinquent'] ?? [];

        // Mark delinquent validators.
        foreach ($delinquent as &$v) {
            $v['_delinquent'] = true;
        }
        unset($v);

        self::$voteAccountsCache = array_merge($current, $delinquent);
        return self::$voteAccountsCache;
    }

    /**
     * Map a Solana vote account to the standard validator schema.
     */
    private function mapValidator(array $v, int $rank): array
    {
        $nodePubkey    = $v['nodePubkey'] ?? '';
        $activatedStake = (float) ($v['activatedStake'] ?? 0) / 1e9; // lamports → SOL
        $commission    = (float) ($v['commission'] ?? 0);
        $isDelinquent  = !empty($v['_delinquent']);

        // Moniker: truncated pubkey.
        $moniker = $nodePubkey;
        if (strlen($moniker) > 16) {
            $moniker = substr($moniker, 0, 6) . '...' . substr($moniker, -4);
        }

        // Approximate uptime from epochCredits: if the validator has recent
        // credits it's been actively voting. Delinquent = low/no uptime.
        $uptime = null;
        if ($isDelinquent) {
            $uptime = 0.0;
        } elseif (!empty($v['epochCredits'])) {
            // Has been voting recently — approximate as high uptime.
            $uptime = 99.0;
        }

        return [
            'operator_address'  => $nodePubkey,
            'chain_id'          => (int) $this->chain->id,
            'moniker'           => $moniker,
            'status'            => $isDelinquent ? 'inactive' : 'active',
            'commission_rate'   => $commission,
            'total_stake'       => round($activatedStake, 6),
            'self_stake'        => null,
            'delegator_count'   => null,
            'uptime_30d'        => $uptime,
            'jailed_count'      => 0,
            'voting_power_rank' => $rank + 1,
        ];
    }

    /**
     * Make a JSON-RPC call to the Solana RPC endpoint.
     */
    private function rpcCall(string $method, array $params): ?array
    {
        $chainId  = (int) $this->chain->id;
        $body     = wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);

        $response = ApiRetry::post($this->rpc_url, [
            'timeout'   => self::HTTP_TIMEOUT,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => $body,
            'sslverify' => true,
        ], [
            'label'    => 'Solana RPC ' . $method,
            'chain_id' => $chainId,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[Solana Fetcher] RPC error for ' . $method . ': ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            \BCC\Core\Log\Logger::error('[Solana Fetcher] HTTP ' . $code . ' for ' . $method);
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($json) || !isset($json['result'])) {
            return null;
        }

        // DAS returns { result: { items: [...] } }
        if (isset($json['result']['items']) && is_array($json['result']['items'])) {
            return $json['result']['items'];
        }

        return is_array($json['result']) ? $json['result'] : null;
    }
}
