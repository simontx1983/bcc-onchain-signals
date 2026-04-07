<?php
/**
 * Enrichment Scheduler
 *
 * Production-grade, DB-driven scheduler that replaces naive cron enrichment.
 *
 * Design principles:
 *  - DB is source of truth (next_enrichment_at, retry_after, enrichment_attempts)
 *  - Redis is used ONLY for atomic API budget counting and cron overlap lock
 *  - Priority: linked validators > missing data > high stake > everything else
 *  - Exponential backoff on failure (15m → 1h → 6h → 24h cap)
 *  - Staggered scheduling prevents thundering herd
 *  - Hard budget cap stops processing when API limit reached
 *
 * @package BCC\Onchain\Services
 */

namespace BCC\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Factories\FetcherFactory;
use BCC\Onchain\Repositories\ChainRepository;
use BCC\Onchain\Repositories\ValidatorRepository;
use BCC\Onchain\Support\CircuitBreaker;

final class EnrichmentScheduler
{
    // ── Budget limits ───────────────────────────────────────────────────────
    const MAX_VALIDATORS_PER_RUN  = 100;
    const MAX_API_CALLS_PER_RUN   = 200;  // global safety cap
    const MAX_API_CALLS_PER_CHAIN = 50;   // per-chain fairness cap

    // ── Refresh intervals (seconds) ─────────────────────────────────────────
    const REFRESH_LINKED  = 4 * HOUR_IN_SECONDS;     // Wallet-linked: 4h base
    const REFRESH_DEFAULT = 24 * HOUR_IN_SECONDS;     // Bulk-indexed: 24h base
    const JITTER_RATIO    = 0.5;                      // ±50% jitter

    // ── Retry backoff ───────────────────────────────────────────────────────
    const BACKOFF_BASE    = 900;                       // 15 minutes
    const BACKOFF_MAX     = 24 * HOUR_IN_SECONDS;      // 24h cap
    const MAX_ATTEMPTS    = 10;                        // Stop retrying after this

    // ── Redis keys ──────────────────────────────────────────────────────────
    const LOCK_KEY        = 'bcc:enrichment_lock';
    const API_COUNTER_KEY = 'bcc:enrichment_api_calls';
    const CACHE_GROUP     = 'bcc_enrichment';
    const LOCK_TTL        = 600;                       // 10 min lock TTL

    /**
     * Run the enrichment scheduler. Called by the hourly cron hook.
     *
     * @return array{processed: int, failed: int, skipped: int, api_calls: int, stopped_reason: string}
     */
    public static function run(): array
    {
        $result = [
            'processed'      => 0,
            'failed'         => 0,
            'skipped'        => 0,
            'api_calls'      => 0,
            'stopped_reason' => 'batch_complete',
        ];

        // ── Redis lock: prevent concurrent runs ─────────────────────────
        if (!self::acquireLock()) {
            $result['stopped_reason'] = 'locked';
            self::log('Scheduler skipped — another run is locked.');
            return $result;
        }

        try {
            // ── Reset API budget counter for this run ────────────────────
            self::resetApiCounter();

            // ── Fetch prioritized batch from DB ─────────────────────────
            $batch = self::fetchBatch();

            if (empty($batch)) {
                $result['stopped_reason'] = 'no_work';
                self::log('Scheduler: no validators due for enrichment.');
                return $result;
            }

            self::log(sprintf('Scheduler: processing batch of %d validators.', count($batch)));

            // ── Process each validator ───────────────────────────────────
            $processed = 0;
            foreach ($batch as $row) {
                // Budget check BEFORE starting work on this validator.
                $apiUsed = self::getApiCount();
                if ($apiUsed >= self::MAX_API_CALLS_PER_RUN) {
                    $result['stopped_reason'] = 'api_budget';
                    self::log("Scheduler: API budget reached ({$apiUsed} calls). Stopping.");
                    break;
                }

                // Heartbeat: extend lock every 10 validators so a slow batch
                // (network timeouts, large enrichments) never loses its lock.
                if ($processed > 0 && $processed % 10 === 0) {
                    self::extendLock();
                }

                // Per-chain fairness: skip validators whose chain has exhausted
                // its budget or whose circuit breaker is open.
                $chainId = (int) ($row->chain_id ?? 0);
                if ($chainId > 0 && (self::isChainBudgetExceeded($chainId) || CircuitBreaker::isOpen($chainId))) {
                    $result['skipped']++;
                    continue;
                }

                try {
                    $callsBefore = self::getApiCount();
                    $enriched    = self::enrichRow($row);

                    if (!$enriched) {
                        $result['skipped']++;
                        continue;
                    }

                    $callsUsed = self::getApiCount() - $callsBefore;
                    self::markSuccess($row, $callsUsed);
                    $result['processed']++;
                } catch (\Throwable $e) {
                    self::markFailure($row, $e->getMessage());
                    $result['failed']++;
                }

                $processed++;
            }

            $result['api_calls'] = self::getApiCount();

        } finally {
            self::releaseLock();
        }

        // Log circuit breaker status for chains that have open breakers.
        $chains = ChainRepository::getActive();
        $chainIds = array_map(fn($c) => (int) $c->id, $chains);
        $cbStatus = CircuitBreaker::getAllStatus($chainIds);
        $openChains = array_filter($cbStatus, fn($s) => $s['status'] !== 'closed');
        if (!empty($openChains)) {
            $openNames = [];
            foreach ($openChains as $cid => $s) {
                foreach ($chains as $c) {
                    if ((int) $c->id === $cid) {
                        $openNames[] = $c->name . '(' . $s['status'] . ',fails=' . $s['failures'] . ')';
                        break;
                    }
                }
            }
            self::log('Circuit breakers: ' . implode(', ', $openNames));
        }

        self::log(sprintf(
            'Scheduler complete: %d processed, %d failed, %d skipped, %d API calls. Stop: %s',
            $result['processed'],
            $result['failed'],
            $result['skipped'],
            $result['api_calls'],
            $result['stopped_reason']
        ));

        return $result;
    }

    // =====================================================================
    // DEAD VALIDATOR CLEANUP
    // =====================================================================

    /**
     * Mark validators as inactive if they haven't been confirmed by the
     * bulk indexer in 30+ days AND have exhausted retry attempts.
     *
     * Called after index_validators() completes. The indexer's bulkUpsert
     * resets enrichment_attempts for every validator it sees (still bonded).
     * So any row that STILL has max attempts after an index run was NOT in
     * the bonded set — it's gone.
     *
     * These rows won't be deleted (historical data), but they'll be marked
     * inactive so the scheduler skips them and the UI shows correct status.
     *
     * @return int Number of validators marked inactive.
     */
    public static function markDeadValidators(): int
    {
        global $wpdb;
        $table = ValidatorRepository::table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'inactive',
                 next_enrichment_at = NULL
             WHERE enrichment_attempts >= %d
               AND fetched_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND status != 'inactive'",
            self::MAX_ATTEMPTS
        ));

        if ($result > 0) {
            self::log(sprintf('Marked %d dead validators as inactive.', $result));
        }

        return (int) $result;
    }

    // =====================================================================
    // BATCH QUERY (Section 3)
    // =====================================================================

    /**
     * Fetch the next batch of validators due for enrichment.
     *
     * Priority order (SQL CASE):
     *   0 — Wallet-linked + missing critical data (someone's profile, no data yet)
     *   1 — Wallet-linked + due for refresh (someone's profile, stale)
     *   2 — Unlinked + missing critical data (discovery, no data yet)
     *   3 — Unlinked + due for refresh (bulk-indexed, stale)
     *
     * Within each tier: highest stake first, then oldest enrichment.
     *
     * Filters:
     *   - next_enrichment_at <= NOW() (scheduled)
     *   - retry_after IS NULL OR retry_after <= NOW() (not in backoff)
     *   - enrichment_attempts < MAX_ATTEMPTS (not permanently failed)
     */
    private static function fetchBatch(): array
    {
        global $wpdb;
        $table = ValidatorRepository::table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE (next_enrichment_at IS NULL OR next_enrichment_at <= NOW())
               AND (retry_after IS NULL OR retry_after <= NOW())
               AND enrichment_attempts < %d
             ORDER BY
                CASE
                    WHEN wallet_link_id IS NOT NULL AND self_stake IS NULL THEN 0
                    WHEN wallet_link_id IS NOT NULL THEN 1
                    WHEN self_stake IS NULL THEN 2
                    ELSE 3
                END ASC,
                total_stake DESC,
                last_enriched_at ASC
             LIMIT %d",
            self::MAX_ATTEMPTS,
            self::MAX_VALIDATORS_PER_RUN
        ));
    }

    // =====================================================================
    // ENRICHMENT EXECUTION (Section 4)
    // =====================================================================

    /**
     * Enrich a single validator row.
     *
     * Wraps existing CosmosFetcher::enrich_validator() — does NOT rewrite
     * the enrichment logic. Returns false if the chain/fetcher is unavailable
     * (skip, not failure).
     */
    private static function enrichRow(object $row): bool
    {
        $chain = ChainRepository::getById((int) $row->chain_id);
        if (!$chain) {
            self::log("SKIP id={$row->id}: chain_id={$row->chain_id} not found");
            return false;
        }
        if (!FetcherFactory::has_driver($chain->chain_type)) {
            self::log("SKIP id={$row->id}: no driver for chain_type={$chain->chain_type}");
            return false;
        }

        $fetcher = FetcherFactory::make_for_chain($chain);

        if (!$fetcher->supports_feature('validator')) {
            self::log("SKIP id={$row->id}: {$chain->chain_type} doesn't support validator");
            return false;
        }

        // Use enrich_validator() when available (skip-if-fresh logic built in).
        $data = method_exists($fetcher, 'enrich_validator')
            ? $fetcher->enrich_validator($row->operator_address, $row)
            : $fetcher->fetch_validator($row->operator_address);

        if (empty($data)) {
            throw new \RuntimeException('Fetcher returned empty data for ' . $row->operator_address);
        }

        ValidatorRepository::enrichByOperator($data, HOUR_IN_SECONDS);
        return true;
    }

    // =====================================================================
    // SUCCESS / FAILURE HANDLERS (Section 6 — Retry)
    // =====================================================================

    /**
     * Mark a validator as successfully enriched.
     *
     * Calculates next_enrichment_at with deterministic jitter:
     *   - Linked validators: 4h ± 50% (2h–6h)
     *   - Unlinked validators: 24h ± 50% (12h–36h)
     *
     * Jitter is seeded from operator_address (same validator always gets
     * the same offset) to distribute load evenly without random drift.
     */
    private static function markSuccess(object $row, int $apiCalls): void
    {
        global $wpdb;
        $table = ValidatorRepository::table();

        $isLinked = ($row->wallet_link_id ?? null) !== null;
        $baseTtl  = $isLinked ? self::REFRESH_LINKED : self::REFRESH_DEFAULT;

        // Deterministic jitter: ±JITTER_RATIO of the base TTL.
        $jitter     = (float) (crc32($row->operator_address) & 0x7FFFFFFF) / 0x7FFFFFFF;
        $multiplier = 1.0 + ($jitter - 0.5) * 2 * self::JITTER_RATIO;
        $ttlSeconds = (int) ($baseTtl * $multiplier);

        $wpdb->update(
            $table,
            [
                'last_enriched_at'    => current_time('mysql', true),
                'next_enrichment_at'  => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
                'retry_after'         => null,
                'enrichment_attempts' => 0,
            ],
            ['id' => (int) $row->id],
            ['%s', '%s', '%s', '%d'],
            ['%d']
        );

        self::log(sprintf(
            'OK id=%d %s — %d API calls, next in %s',
            $row->id,
            $row->operator_address,
            $apiCalls,
            self::humanSeconds($ttlSeconds)
        ));
    }

    /**
     * Mark a validator as failed with exponential backoff.
     *
     * Backoff formula: min(24h, 15min × 4^(attempts-1))
     *   Attempt 1 → 15 min
     *   Attempt 2 → 1 hour
     *   Attempt 3 → 4 hours
     *   Attempt 4 → 16 hours
     *   Attempt 5+ → 24 hours (capped)
     *
     * Does NOT update next_enrichment_at — the row stays "due" but the
     * retry_after gate prevents processing until the backoff expires.
     */
    private static function markFailure(object $row, string $error): void
    {
        global $wpdb;
        $table = ValidatorRepository::table();

        $attempts     = ((int) ($row->enrichment_attempts ?? 0)) + 1;
        $backoffSec   = (int) min(self::BACKOFF_MAX, self::BACKOFF_BASE * pow(4, $attempts - 1));
        $retryAfter   = gmdate('Y-m-d H:i:s', time() + $backoffSec);

        $wpdb->update(
            $table,
            [
                'enrichment_attempts' => $attempts,
                'retry_after'         => $retryAfter,
            ],
            ['id' => (int) $row->id],
            ['%d', '%s'],
            ['%d']
        );

        self::log(sprintf(
            'FAIL id=%d %s — attempt %d, retry in %s. Error: %s',
            $row->id,
            $row->operator_address,
            $attempts,
            self::humanSeconds($backoffSec),
            substr($error, 0, 200)
        ));
    }

    // =====================================================================
    // REDIS: LOCK + API COUNTER (Section 5)
    // =====================================================================

    /**
     * Acquire Redis lock. Returns false if another run is in progress.
     * Uses wp_cache_add() which is atomic — only succeeds if key doesn't exist.
     */
    private static function acquireLock(): bool
    {
        return (bool) wp_cache_add(self::LOCK_KEY, time(), self::CACHE_GROUP, self::LOCK_TTL);
    }

    /**
     * Extend the lock TTL (heartbeat). Called every 10 validators during
     * processing so a slow batch never loses its lock mid-run.
     * If the process crashes, the lock still expires after LOCK_TTL seconds.
     */
    private static function extendLock(): void
    {
        wp_cache_set(self::LOCK_KEY, time(), self::CACHE_GROUP, self::LOCK_TTL);
    }

    private static function releaseLock(): void
    {
        wp_cache_delete(self::LOCK_KEY, self::CACHE_GROUP);
    }

    /**
     * Reset all API call counters at the start of each run.
     * Clears global + per-chain counters so stale counts from the
     * indexer (same PHP request) don't block enrichment.
     */
    private static function resetApiCounter(): void
    {
        wp_cache_set(self::API_COUNTER_KEY, 0, self::CACHE_GROUP, self::LOCK_TTL);

        // Reset per-chain counters for all active chains.
        $chains = ChainRepository::getActive();
        foreach ($chains as $chain) {
            $chainKey = self::API_COUNTER_KEY . ':' . (int) $chain->id;
            wp_cache_delete($chainKey, self::CACHE_GROUP);
        }
    }

    /**
     * Atomically increment BOTH the global and per-chain API call counters.
     * Called by the fetcher's lcdGet wrapper.
     *
     * @param int $chainId Chain ID for per-chain budget tracking.
     */
    public static function trackApiCall(int $chainId = 0): void
    {
        // Global counter.
        $result = wp_cache_incr(self::API_COUNTER_KEY, 1, self::CACHE_GROUP);
        if ($result === false) {
            wp_cache_set(self::API_COUNTER_KEY, 1, self::CACHE_GROUP, self::LOCK_TTL);
        }

        // Per-chain counter (if chain specified).
        if ($chainId > 0) {
            $chainKey = self::API_COUNTER_KEY . ':' . $chainId;
            $result   = wp_cache_incr($chainKey, 1, self::CACHE_GROUP);
            if ($result === false) {
                wp_cache_set($chainKey, 1, self::CACHE_GROUP, self::LOCK_TTL);
            }
        }
    }

    /**
     * Read current global API call count.
     */
    public static function getApiCount(): int
    {
        return (int) (wp_cache_get(self::API_COUNTER_KEY, self::CACHE_GROUP) ?: 0);
    }

    /**
     * Read current per-chain API call count.
     */
    public static function getChainApiCount(int $chainId): int
    {
        $chainKey = self::API_COUNTER_KEY . ':' . $chainId;
        return (int) (wp_cache_get($chainKey, self::CACHE_GROUP) ?: 0);
    }

    /**
     * Check if a chain has exceeded its per-chain budget.
     */
    public static function isChainBudgetExceeded(int $chainId): bool
    {
        return self::getChainApiCount($chainId) >= self::MAX_API_CALLS_PER_CHAIN;
    }

    // =====================================================================
    // LOGGING
    // =====================================================================

    private static function log(string $message): void
    {
        \BCC\Core\Log\Logger::info('[Enrichment] ' . $message);
    }

    private static function humanSeconds(int $seconds): string
    {
        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }
        return round($seconds / 3600, 1) . 'h';
    }
}
