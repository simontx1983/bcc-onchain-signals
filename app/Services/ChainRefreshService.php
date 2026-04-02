<?php

namespace BCC\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Factories\FetcherFactory;
use BCC\Onchain\Repositories\ChainRepository;
use BCC\Onchain\Repositories\CollectionRepository;
use BCC\Onchain\Repositories\ValidatorRepository;
use BCC\Onchain\Repositories\WalletRepository;
use BCC\Onchain\Services\CollectionService;

/**
 * Chain Refresh Cron
 *
 * Separate cron jobs per data type, each with its own interval.
 *
 * Jobs:
 *  - bcc_refresh_validators   (every 1 hour)
 *  - bcc_refresh_collections  (every 4 hours)
 */
class ChainRefreshService
{
    const BATCH_SIZE = 50;

    /**
     * Register cron hooks.
     */
    public static function init(): void
    {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_intervals']);

        add_action('bcc_refresh_validators',  [__CLASS__, 'refresh_validators']);
        add_action('bcc_refresh_collections', [__CLASS__, 'refresh_collections']);
        add_action('bcc_index_validators',   [__CLASS__, 'index_validators']);
        add_action('bcc_index_collections',  [__CLASS__, 'index_collections']);

        add_action('admin_init', [__CLASS__, 'schedule_crons']);
    }

    /**
     * Register custom cron intervals.
     */
    public static function add_cron_intervals(array $schedules): array
    {
        $schedules['every_4_hours'] = [
            'interval' => 4 * HOUR_IN_SECONDS,
            'display'  => 'Every 4 Hours',
        ];
        return $schedules;
    }

    /**
     * Schedule recurring cron jobs.
     */
    public static function schedule_crons(): void
    {
        $jobs = [
            'bcc_refresh_validators'  => 'hourly',
            'bcc_refresh_collections' => 'every_4_hours',
            'bcc_index_validators'    => 'every_4_hours',
            'bcc_index_collections'   => 'every_4_hours',
        ];

        foreach ($jobs as $hook => $interval) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), $interval, $hook);
            }
        }
    }

    /**
     * Clear all cron jobs on deactivation.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('bcc_refresh_validators');
        wp_clear_scheduled_hook('bcc_refresh_collections');
        wp_clear_scheduled_hook('bcc_index_validators');
        wp_clear_scheduled_hook('bcc_index_collections');
    }

    // ── Validator Indexing (bulk — all validators per chain) ────────────────

    /**
     * Fetch and store ALL active validators for every Cosmos chain.
     *
     * This is the discovery/seeding path — populates the validators table
     * with the full active set so the discovery UI and claim system have
     * data to display. Runs every 4 hours.
     *
     * The per-validator refresh cron (refresh_validators) handles expensive
     * enrichment (uptime, governance, delegations) on expired rows.
     */
    public static function index_validators(): void
    {
        $cosmos_chains = ChainRepository::getActive('cosmos');

        foreach ($cosmos_chains as $chain) {
            try {
                if (!FetcherFactory::has_driver($chain->chain_type)) {
                    continue;
                }

                $fetcher = FetcherFactory::make_for_chain($chain);

                if (!method_exists($fetcher, 'fetch_all_validators')) {
                    continue;
                }

                $validators = $fetcher->fetch_all_validators();

                if (!empty($validators)) {
                    $count = ValidatorRepository::bulkUpsert($validators, 4 * HOUR_IN_SECONDS);
                    error_log("[BCC Onchain] Indexed {$count} validators for {$chain->name}");
                }
            } catch (\Exception $e) {
                error_log("[BCC Onchain] Validator index failed for {$chain->name}: " . $e->getMessage());
            }
        }
    }

    // ── Collection Indexing (bulk — top NFT collections per EVM chain) ─────

    /**
     * Fetch and store top NFT collections for every EVM chain.
     *
     * Uses Reservoir API (free tier) to get the same data shown on
     * etherscan.io/nft-top-contracts: name, floor, volume, holders, image.
     * Runs every 4 hours. Collections with wallet_link_id = NULL are
     * unclaimed — displayed with "Claim Your Community" button.
     */
    public static function index_collections(): void
    {
        $evm_chains = ChainRepository::getActive('evm');

        foreach ($evm_chains as $chain) {
            try {
                if (!FetcherFactory::has_driver($chain->chain_type)) {
                    continue;
                }

                $fetcher = FetcherFactory::make_for_chain($chain);

                if (!method_exists($fetcher, 'fetch_top_collections')) {
                    continue;
                }

                $collections = $fetcher->fetch_top_collections(100);

                if (!empty($collections)) {
                    $count = CollectionRepository::bulkUpsert($collections, 4 * HOUR_IN_SECONDS);
                    error_log("[BCC Onchain] Indexed {$count} collections for {$chain->name}");
                }
            } catch (\Exception $e) {
                error_log("[BCC Onchain] Collection index failed for {$chain->name}: " . $e->getMessage());
            }
        }
    }

    // ── Validator Refresh (per-row — enriches expired validators) ─────────

    public static function refresh_validators(): void
    {
        $expired = ValidatorRepository::getExpired(self::BATCH_SIZE);

        if (empty($expired)) {
            return;
        }

        foreach ($expired as $row) {
            try {
                $chain = ChainRepository::getById((int) $row->chain_id);
                if (!$chain || !FetcherFactory::has_driver($chain->chain_type)) {
                    continue;
                }

                $fetcher = FetcherFactory::make_for_chain($chain);

                if (!$fetcher->supports_feature('validator')) {
                    continue;
                }

                $data = $fetcher->fetch_validator($row->operator_address);

                if (!empty($data)) {
                    ValidatorRepository::upsert($data, (int) $row->wallet_link_id, HOUR_IN_SECONDS);
                }
            } catch (\Exception $e) {
                error_log("BCC Refresh: Validator {$row->operator_address} failed — " . $e->getMessage());
                self::backoffRow(ValidatorRepository::table(), (int) $row->id);
            }
        }
    }

    // ── Collection Refresh ──────────────────────────────────────────────────

    public static function refresh_collections(): void
    {
        $expired = CollectionRepository::getExpired(self::BATCH_SIZE);

        if (empty($expired)) {
            return;
        }

        foreach ($expired as $row) {
            try {
                $chain = ChainRepository::getById((int) $row->chain_id);
                if (!$chain || !FetcherFactory::has_driver($chain->chain_type)) {
                    continue;
                }

                $fetcher = FetcherFactory::make_for_chain($chain);

                if (!$fetcher->supports_feature('collection')) {
                    continue;
                }

                // Resolve the wallet address from the wallet link
                $wallet = WalletRepository::getById((int) $row->wallet_link_id);
                if (!$wallet) {
                    continue;
                }

                $collections = $fetcher->fetch_collections($wallet->wallet_address, (int) $row->chain_id);

                foreach ($collections as $collection) {
                    CollectionRepository::upsert($collection, (int) $row->wallet_link_id, 4 * HOUR_IN_SECONDS);
                }

                if (!empty($collections) && (int) $wallet->post_id > 0) {
                    CollectionService::invalidate((int) $wallet->post_id);
                }
            } catch (\Exception $e) {
                error_log("BCC Refresh: Collection {$row->contract_address} failed — " . $e->getMessage());
                self::backoffRow(CollectionRepository::table(), (int) $row->id);
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Exponential backoff: push expires_at forward by 2x the original TTL.
     */
    private static function backoffRow(string $table, int $row_id): void
    {
        global $wpdb;

        $allowed_prefix = $wpdb->prefix . 'bcc_';
        if (strpos($table, $allowed_prefix) !== 0) {
            error_log('[BCC Onchain] Backoff rejected for untrusted table: ' . $table);
            return;
        }

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET expires_at = DATE_ADD(NOW(), INTERVAL TIMESTAMPDIFF(SECOND, fetched_at, expires_at) * 2 SECOND)
             WHERE id = %d",
            $row_id
        ));

        if ($result === false) {
            error_log('[BCC Onchain] Backoff update failed for ' . $table . ' row ' . $row_id . ': ' . $wpdb->last_error);
        }
    }
}
