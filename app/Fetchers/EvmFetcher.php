<?php

namespace BCC\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Contracts\CollectionFetcherInterface;
use BCC\Onchain\Contracts\FetcherInterface;

/**
 * EVM Chain Fetcher
 *
 * Fetches NFT collection data from EVM chains via Etherscan-compatible APIs.
 * Uses the ERC-721/1155 token transfer endpoint to discover collections
 * created by an address, then enriches with supply and holder data.
 *
 * Requires BCC_ETHERSCAN_API_KEY defined in wp-config.php.
 */
class EvmFetcher implements FetcherInterface, CollectionFetcherInterface
{
    private const HTTP_TIMEOUT = 12;

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
        return []; // EVM chains don't expose validator data via Etherscan API
    }

    /**
     * Discover NFT collections created by an address.
     *
     * Strategy: query Etherscan "tokennfttx" for outbound ERC-721/1155
     * transfers where from=0x0 (mints) and contractAddress was deployed
     * by this address. Groups results by contract.
     *
     * Implements both FetcherInterface (1-param) and CollectionFetcherInterface (2-param).
     *
     * @param string $walletAddress  Wallet address to query.
     * @param int    $chainId        Chain ID override (ignored — uses $this->chain->id).
     * @return array[] Array of normalized collection rows.
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array
    {
        $chainId = $chainId ?: (int) $this->chain->id;

        $api_key = defined('BCC_ETHERSCAN_API_KEY') ? BCC_ETHERSCAN_API_KEY : '';
        if (!$api_key) {
            return [];
        }

        $explorer = rtrim($this->chain->explorer_url ?? '', '/');
        $api_base = $this->resolveApiBase($explorer);

        // Fetch ERC-721 token transfer events for this address
        $transfers = $this->etherscanGet($api_base, [
            'module'     => 'account',
            'action'     => 'tokennfttx',
            'address'    => $walletAddress,
            'startblock' => 0,
            'endblock'   => 99999999,
            'page'       => 1,
            'offset'     => 500,
            'sort'       => 'asc',
            'apikey'     => $api_key,
        ]);

        if (!is_array($transfers) || empty($transfers)) {
            return [];
        }

        // Group by contract address — only keep contracts where this address
        // minted (from = 0x0) which indicates creator/deployer role
        $contracts = [];
        $zero      = '0x0000000000000000000000000000000000000000';

        foreach ($transfers as $tx) {
            $contract = strtolower($tx->contractAddress ?? '');
            if (!$contract) {
                continue;
            }

            if (!isset($contracts[$contract])) {
                $contracts[$contract] = [
                    'contract_address' => $tx->contractAddress,
                    'collection_name'  => $tx->tokenName ?? null,
                    'token_standard'   => 'ERC-721',
                    'mint_count'       => 0,
                ];
            }

            // Count mints (from zero address)
            if (strtolower($tx->from ?? '') === $zero) {
                $contracts[$contract]['mint_count']++;
            }
        }

        // Only keep collections where this address was involved in minting
        $created = array_filter($contracts, fn($c) => $c['mint_count'] > 0);

        if (empty($created)) {
            return [];
        }

        // Normalize into the schema format
        $native = $this->chain->native_token ?? 'ETH';

        $collections = [];
        foreach ($created as $meta) {
            $collections[] = [
                'contract_address'   => $meta['contract_address'],
                'collection_name'    => $meta['collection_name'],
                'chain_id'           => $chainId,
                'token_standard'     => $meta['token_standard'],
                'total_supply'       => $meta['mint_count'] > 0 ? $meta['mint_count'] : null,
                'floor_price'        => null,
                'floor_currency'     => $native,
                'total_volume'       => null,
                'unique_holders'     => null,
                'listed_percentage'  => null,
                'royalty_percentage' => null,
                'metadata_storage'   => null,
            ];
        }

        return $collections;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Derive the Etherscan-compatible API base URL from an explorer URL.
     * e.g. https://etherscan.io → https://api.etherscan.io/api
     *      https://polygonscan.com → https://api.polygonscan.com/api
     */
    private function resolveApiBase(string $explorerUrl): string
    {
        if (!$explorerUrl) {
            return 'https://api.etherscan.io/api';
        }

        $parsed = parse_url($explorerUrl);
        $host   = $parsed['host'] ?? '';

        // etherscan.io → api.etherscan.io
        if (str_contains($host, 'etherscan.io')) {
            return 'https://api.etherscan.io/api';
        }

        // *scan.com (polygonscan, arbiscan, basescan, bscscan, etc.)
        return 'https://api.' . $host . '/api';
    }

    private function etherscanGet(string $apiBase, array $params): ?array
    {
        $url      = add_query_arg($params, $apiBase);
        $response = wp_remote_get($url, ['timeout' => self::HTTP_TIMEOUT, 'sslverify' => true]);

        if (is_wp_error($response)) {
            error_log('[BCC Onchain] EVM collection fetch failed: ' . $response->get_error_message());
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response));

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (isset($json->status) && $json->status === '1' && is_array($json->result)) {
            return $json->result;
        }

        return null;
    }
}
