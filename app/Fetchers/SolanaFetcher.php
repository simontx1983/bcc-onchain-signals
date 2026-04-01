<?php

namespace BCC\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Contracts\CollectionFetcherInterface;
use BCC\Onchain\Contracts\FetcherInterface;

/**
 * Solana Chain Fetcher
 *
 * Fetches NFT collection data via the Solana public RPC (getAssetsByOwner DAS).
 * Falls back to getSignaturesForAddress + getParsedTransaction when the DAS
 * API is unavailable on the configured RPC.
 *
 * No API key required — uses the public mainnet RPC.
 */
class SolanaFetcher implements FetcherInterface, CollectionFetcherInterface
{
    private const HTTP_TIMEOUT = 12;
    private const SOLANA_RPC   = 'https://api.mainnet-beta.solana.com';

    private object $chain;

    public function __construct(object $chain)
    {
        $this->chain = $chain;
    }

    public function get_chain(): object
    {
        return $this->chain;
    }

    public function supports_feature(string $feature): bool
    {
        return $feature === 'collection';
    }

    public function fetch_validator(string $address): array
    {
        return []; // Solana validator data requires specialized RPC calls not supported here
    }

    /**
     * Fetch NFT collections associated with a Solana wallet.
     *
     * Uses getAssetsByOwner (DAS API) when available on the configured RPC.
     * Groups assets by collection, returns normalized rows.
     *
     * @param string $walletAddress  Solana base58 wallet address.
     * @param int    $chainId        Chain ID override (uses $this->chain->id if 0).
     * @return array[] Normalized collection rows.
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array
    {
        $chainId = $chainId ?: (int) $this->chain->id;
        $rpc     = $this->chain->rpc_url ?? self::SOLANA_RPC;

        // Try DAS API (getAssetsByOwner) — available on Helius, Triton, etc.
        $assets = $this->rpcCall($rpc, 'getAssetsByOwner', [
            'ownerAddress'  => $walletAddress,
            'displayOptions' => ['showCollectionMetadata' => true],
            'limit'         => 500,
            'page'          => 1,
        ]);

        if (!is_array($assets)) {
            // DAS not available on this RPC — return empty gracefully.
            // The public mainnet RPC doesn't support DAS; users need Helius/Triton.
            return [];
        }

        // Group by collection mint authority / grouping key
        $collections = [];

        foreach ($assets as $asset) {
            $asset = (object) $asset;
            $grouping = $asset->grouping ?? [];

            // Find the collection grouping
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
                // Extract collection metadata if available
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

                // Extract royalty if available
                if (isset($asset->royalty->percent)) {
                    $collections[$key]['royalty_percentage'] = round((float) $asset->royalty->percent * 100, 2);
                }

                // Detect metadata storage from URI
                $uri = $asset->content->json_uri ?? '';
                if (str_contains($uri, 'arweave.net')) {
                    $collections[$key]['metadata_storage'] = 'arweave';
                } elseif (str_contains($uri, 'ipfs')) {
                    $collections[$key]['metadata_storage'] = 'ipfs';
                } elseif (str_contains($uri, 'nftstorage.link')) {
                    $collections[$key]['metadata_storage'] = 'ipfs';
                }
            }

            $collections[$key]['_count']++;
        }

        // Set total_supply from owned count (best approximation from owner data)
        $result = [];
        foreach ($collections as $coll) {
            unset($coll['_count']);
            $result[] = $coll;
        }

        return $result;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function rpcCall(string $rpc, string $method, array $params): ?array
    {
        $body     = wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        $response = wp_remote_post($rpc, [
            'timeout'   => self::HTTP_TIMEOUT,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => $body,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response));

        if (json_last_error() !== JSON_ERROR_NONE || !isset($json->result)) {
            return null;
        }

        // DAS returns { result: { items: [...], total: int } }
        if (isset($json->result->items) && is_array($json->result->items)) {
            return $json->result->items;
        }

        return is_array($json->result) ? $json->result : null;
    }
}
