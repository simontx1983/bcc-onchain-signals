<?php

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\DB\DB;

/**
 * Fetches raw on-chain signals from Etherscan (Ethereum) and Solana public RPC.
 *
 * Required constants (define in wp-config.php):
 *   BCC_ETHERSCAN_API_KEY  — https://etherscan.io/myapikey (free)
 *
 * Solana uses the public mainnet RPC — no key required for basic queries.
 */
class BCC_Onchain_Fetcher
{
    const ETHERSCAN_BASE = 'https://api.etherscan.io/api';
    const SOLANA_RPC     = 'https://api.mainnet-beta.solana.com';
    const HTTP_TIMEOUT   = 10; // seconds

    // ── Public ────────────────────────────────────────────────────────────────

    /**
     * Validate wallet address format before making external API calls.
     * Rejects malformed addresses to avoid wasting API quota.
     */
    public static function validate_address(string $address, string $chain): bool
    {
        return match ($chain) {
            'ethereum' => (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $address),
            'solana'   => (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address),
            'cosmos'   => (bool) preg_match('/^[a-z]{1,20}1[a-z0-9]{38,58}$/', $address),
            default    => false,
        };
    }

    /**
     * Fetch signals for one wallet on one chain.
     *
     * @param bool $force  Delete transient cache and force a fresh API call.
     * @return array|null  Associative array of signal data, or null on API error.
     */
    public static function fetch(string $address, string $chain, bool $force = false): ?array
    {
        if (!self::validate_address($address, $chain)) {
            error_log("[BCC On-chain] Invalid {$chain} address format: {$address}");
            return null;
        }

        $cache_key = 'bcc_onchain_' . md5( $address . $chain );

        if ( $force ) {
            delete_transient( $cache_key );
        }

        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $result = match ($chain) {
            'ethereum' => self::fetch_ethereum($address),
            'solana'   => self::fetch_solana($address),
            default    => null,
        };

        if ( $result !== null ) {
            // Cache for 6 hours — on-chain data doesn't change by the minute.
            set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
        }

        return $result;
    }

    /**
     * Return all wallets connected to a user, keyed by chain.
     * Reads from bcc_trust_user_verifications (trust engine table).
     *
     * @return array  ['ethereum' => ['0xABC…'], 'solana' => ['abc…']]
     */
    public static function get_connected_wallets(int $user_id): array
    {
        global $wpdb;

        $table = DB::table('trust_user_verifications');
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT type, provider_username AS address
             FROM {$table}
             WHERE user_id = %d
               AND type IN ('wallet_ethereum', 'wallet_solana', 'wallet_cosmos')
               AND status = 'active'",
            $user_id
        ));

        $wallets = [];
        foreach ($rows as $r) {
            $chain = str_replace('wallet_', '', $r->type); // 'ethereum', 'solana', 'cosmos'
            if ($r->address) {
                $wallets[$chain][] = $r->address;
            }
        }

        return $wallets;
    }

    // ── Ethereum ──────────────────────────────────────────────────────────────

    private static function fetch_ethereum(string $address): ?array
    {
        $api_key = defined('BCC_ETHERSCAN_API_KEY') ? BCC_ETHERSCAN_API_KEY : '';

        if (!$api_key) {
            error_log('[BCC On-chain] BCC_ETHERSCAN_API_KEY not defined in wp-config.php');
        }

        // Single API call: fetch up to 1000 transactions sorted ascending
        $all_txs = self::etherscan_request([
            'module'     => 'account',
            'action'     => 'txlist',
            'address'    => $address,
            'startblock' => 0,
            'endblock'   => 99999999,
            'page'       => 1,
            'offset'     => 1000,
            'sort'       => 'asc',
            'apikey'     => $api_key,
        ]);

        // API error — return null so callers don't store zero-data as valid.
        // Matches fetch_solana() behavior on RPC failure.
        if ($all_txs === null) {
            return null;
        }

        $first_tx_at     = null;
        $wallet_age_days = 0;
        $tx_count        = 0;
        $contract_count  = 0;

        if (is_array($all_txs)) {
            $tx_count = count($all_txs);

            // First transaction timestamp (oldest — first element in asc order)
            if ($tx_count > 0 && !empty($all_txs[0]->timeStamp)) {
                $ts              = (int) $all_txs[0]->timeStamp;
                $first_tx_at     = gmdate('Y-m-d H:i:s', $ts);
                $wallet_age_days = (int) floor((time() - $ts) / DAY_IN_SECONDS);
            }

            // Contract deployments (txs where contractAddress is set, sent by this address)
            foreach ($all_txs as $tx) {
                if (isset($tx->contractAddress) && $tx->contractAddress !== '' && strtolower($tx->from ?? '') === strtolower($address)) {
                    $contract_count++;
                }
            }
        }

        return [
            'wallet_age_days' => $wallet_age_days,
            'first_tx_at'     => $first_tx_at,
            'tx_count'        => $tx_count,
            'contract_count'  => $contract_count,
            'raw_data'        => [
                'source'          => 'etherscan',
                'wallet_age_days' => $wallet_age_days,
                'tx_count'        => $tx_count,
                'contract_count'  => $contract_count,
            ],
        ];
    }

    // ── Solana ────────────────────────────────────────────────────────────────

    private static function fetch_solana(string $address): ?array
    {
        // Get signatures (up to 1000 at a time — Solana RPC limit)
        $signatures = self::solana_rpc('getSignaturesForAddress', [
            $address,
            ['limit' => 1000],
        ]);

        if ($signatures === null) {
            return null;
        }

        $tx_count        = count($signatures);
        $first_tx_at     = null;
        $wallet_age_days = 0;

        // The last item in the array is the oldest (descending order)
        if ($tx_count > 0) {
            $oldest = end($signatures);
            if (isset($oldest->blockTime) && $oldest->blockTime) {
                $ts              = (int) $oldest->blockTime;
                $first_tx_at     = gmdate('Y-m-d H:i:s', $ts);
                $wallet_age_days = (int) floor((time() - $ts) / DAY_IN_SECONDS);
            }
        }

        // Solana RPC returns at most 1000 signatures per call.
        // Flag when the count is capped so scoring knows the real number is higher.
        $tx_count_capped = ($tx_count === 1000);

        // Solana: detecting program deployments requires checking for BPFLoaderUpgradeable
        // program in the tx — skip for now, set contract_count = 0
        $contract_count = 0;

        return [
            'wallet_age_days' => $wallet_age_days,
            'first_tx_at'     => $first_tx_at,
            'tx_count'        => $tx_count,
            'contract_count'  => $contract_count,
            'raw_data'        => [
                'source'           => 'solana_rpc',
                'wallet_age_days'  => $wallet_age_days,
                'tx_count'         => $tx_count,
                'tx_count_capped'  => $tx_count_capped,
            ],
        ];
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private static function etherscan_request(array $params): ?array
    {
        $raw = self::etherscan_request_raw($params);
        if ($raw && isset($raw->status) && $raw->status === '1' && is_array($raw->result)) {
            return $raw->result;
        }
        return null;
    }

    private static function etherscan_request_raw(array $params): ?object
    {
        $url      = add_query_arg($params, self::ETHERSCAN_BASE);
        $response = wp_remote_get($url, ['timeout' => self::HTTP_TIMEOUT, 'sslverify' => true]);

        if (is_wp_error($response)) {
            error_log('[BCC On-chain] Etherscan request failed: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[BCC On-chain] Etherscan JSON decode error');
            return null;
        }

        return $json;
    }

    private static function solana_rpc(string $method, array $params): ?array
    {
        $body     = wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        $response = wp_remote_post(self::SOLANA_RPC, [
            'timeout'     => self::HTTP_TIMEOUT,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => $body,
            'sslverify'   => true,
        ]);

        if (is_wp_error($response)) {
            error_log('[BCC On-chain] Solana RPC request failed: ' . $response->get_error_message());
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response));

        if (json_last_error() !== JSON_ERROR_NONE || !isset($json->result)) {
            error_log('[BCC On-chain] Solana RPC invalid response');
            return null;
        }

        return is_array($json->result) ? $json->result : null;
    }
}
