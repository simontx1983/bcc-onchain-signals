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
use BCC\Onchain\Support\CircuitBreaker;

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

    // ── Locking ──────────────────────────────────────────────────────────────

    private const LOCK_GROUP = 'bcc_cron';

    /**
     * Acquire an atomic Redis-backed lock for a cron job.
     * wp_cache_add() only succeeds if the key doesn't exist — atomic.
     */
    private static function acquireLock(string $job, int $ttl = 900): bool
    {
        $acquired = wp_cache_add('lock_' . $job, time(), self::LOCK_GROUP, $ttl);

        if (!$acquired) {
            \BCC\Core\Log\Logger::info('[Onchain] Skipping ' . $job . ' — previous run still locked.');
            return false;
        }

        return true;
    }

    private static function releaseLock(string $job): void
    {
        wp_cache_delete('lock_' . $job, self::LOCK_GROUP);
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
        if (!self::acquireLock('index_validators', 1800)) {
            return;
        }

        // Index all chain types that support validators.
        $chains = array_merge(
            ChainRepository::getActive('cosmos'),
            ChainRepository::getActive('thorchain'),
            ChainRepository::getActive('solana'),
            ChainRepository::getActive('polkadot'),
            ChainRepository::getActive('near')
        );

        foreach ($chains as $chain) {
            $chainId = (int) $chain->id;

            // Skip chains whose circuit breaker is open (consistently failing).
            if (CircuitBreaker::isOpen($chainId)) {
                \BCC\Core\Log\Logger::info('[Onchain] Skipping index for ' . $chain->name . ' — circuit breaker open');
                continue;
            }

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
                    $stats = ValidatorRepository::bulkUpsert($validators, 4 * HOUR_IN_SECONDS);

                    // Persist per-chain stats for the admin dashboard.
                    $allStats = get_option('bcc_onchain_indexer_stats', []);
                    $allStats[$chain->slug] = array_merge($stats, [
                        'chain'     => $chain->name,
                        'timestamp' => current_time('mysql', true),
                    ]);
                    update_option('bcc_onchain_indexer_stats', $allStats, false);

                    \BCC\Core\Log\Logger::info(sprintf(
                        '[Onchain] Indexed %s: %d total, %d new, %d updated, %d unchanged, %d refreshed',
                        $chain->name, $stats['total'], $stats['new'], $stats['updated'],
                        $stats['unchanged'], $stats['refreshed'] ?? 0
                    ));

                    CircuitBreaker::recordSuccess($chainId);
                } else {
                    // Empty result from an active chain is suspicious
                    CircuitBreaker::recordFailure($chainId);
                    \BCC\Core\Log\Logger::warning('[Onchain] Validator index returned empty for ' . $chain->name);
                }
            } catch (\Exception $e) {
                CircuitBreaker::recordFailure($chainId);
                \BCC\Core\Log\Logger::error('[Onchain] Validator index failed for ' . $chain->name . ': ' . $e->getMessage());
            }
        }

        // After indexing, clean up validators that the indexer hasn't seen
        // in 30+ days and have exhausted retry attempts — they're gone.
        EnrichmentScheduler::markDeadValidators();

        self::releaseLock('index_validators');
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
        if (!self::acquireLock('index_collections', 1800)) {
            return;
        }

        // Process all chain types that may support top collections.
        // Each chain type is indexed independently — no cross-chain mixing.
        $chain_types = ['evm', 'solana', 'cosmos'];

        foreach ($chain_types as $type) {
            $chains = ChainRepository::getActive($type);

            foreach ($chains as $chain) {
                $chainId = (int) $chain->id;

                if (CircuitBreaker::isOpen($chainId)) {
                    \BCC\Core\Log\Logger::info('[Onchain] Skipping collection index for ' . $chain->name . ' — circuit breaker open');
                    continue;
                }

                try {
                    if (!FetcherFactory::has_driver($chain->chain_type)) {
                        continue;
                    }

                    $fetcher = FetcherFactory::make_for_chain($chain);

                    if (!$fetcher->supports_feature('top_collections')) {
                        continue;
                    }

                    $collections = $fetcher->fetch_top_collections(100);

                    if (!empty($collections)) {
                        $count = CollectionRepository::bulkUpsert($collections, 4 * HOUR_IN_SECONDS);
                        \BCC\Core\Log\Logger::info('[Onchain] Indexed ' . $count . ' collections for ' . $chain->name);
                        CircuitBreaker::recordSuccess($chainId);
                    }
                } catch (\Exception $e) {
                    CircuitBreaker::recordFailure($chainId);
                    \BCC\Core\Log\Logger::error('[Onchain] Collection index failed for ' . $chain->name . ': ' . $e->getMessage());
                }
            }
        }

        self::releaseLock('index_collections');
    }

    // ── Validator Refresh (scheduler-driven) ─────────────────────────────────

    /**
     * Enrich validators via the EnrichmentScheduler.
     *
     * The scheduler handles: priority ordering, API budget control,
     * retry/backoff, staggered scheduling, and Redis-based overlap prevention.
     * This method is just the cron entry point.
     */
    public static function refresh_validators(): void
    {
        $stats = EnrichmentScheduler::run();

        // Persist enrichment stats for the admin dashboard.
        update_option('bcc_onchain_enrichment_stats', array_merge($stats, [
            'timestamp' => current_time('mysql', true),
        ]), false);
    }

    // ── Collection Refresh ──────────────────────────────────────────────────

    public static function refresh_collections(): void
    {
        if (!self::acquireLock('refresh_collections', 900)) {
            return;
        }

        $expired = CollectionRepository::getExpired(self::BATCH_SIZE);

        if (empty($expired)) {
            self::releaseLock('refresh_collections');
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

                if (!empty($collections)) {
                    foreach ($collections as $collection) {
                        CollectionRepository::upsert($collection, (int) $row->wallet_link_id, 4 * HOUR_IN_SECONDS);
                    }

                    if ((int) $wallet->post_id > 0) {
                        CollectionService::invalidate((int) $wallet->post_id);
                    }
                } else {
                    // Empty response after retries — backoff to prevent tight re-fetch
                    // loop on wallets with deleted collections or failing chain APIs.
                    CollectionRepository::backoffRow((int) $row->id);
                }
            } catch (\Exception $e) {
                CircuitBreaker::recordFailure((int) $row->chain_id);
                \BCC\Core\Log\Logger::error('[Onchain] Collection ' . $row->contract_address . ' refresh failed: ' . $e->getMessage());
                CollectionRepository::backoffRow((int) $row->id);
            }
        }

        self::releaseLock('refresh_collections');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

}
