<?php
/**
 * Circuit Breaker
 *
 * Per-chain circuit breaker that pauses API fetching when a chain's
 * endpoint is consistently failing. Prevents wasting API budget on
 * chains that are down and gives failing endpoints time to recover.
 *
 * States:
 *   CLOSED   — Normal operation, requests flow through.
 *   OPEN     — Chain is failing, all requests blocked for COOLDOWN period.
 *   HALF-OPEN — Cooldown expired, allow ONE probe request.
 *              If it succeeds → CLOSED. If it fails → OPEN again.
 *
 * Storage: wp_cache (Redis-backed when available, transient fallback).
 *
 * @package BCC\Onchain\Support
 */

namespace BCC\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class CircuitBreaker
{
    const FAILURE_THRESHOLD = 5;            // Consecutive failures to trip
    const COOLDOWN_SECONDS  = 300;          // 5 minutes before half-open probe
    const CACHE_GROUP       = 'bcc_circuit';
    // 6-hour TTL: long-running batch cron jobs (10k+ pages) can exceed the
    // previous 30-min TTL mid-run, causing an OPEN breaker to silently return
    // to CLOSED in the same cron tick because its cached state expired. The
    // TTL only needs to outlast the longest batch run; breaker state is
    // authoritatively reset by recordSuccess() so a longer TTL does not
    // extend actual cooldowns — it just prevents premature amnesia.
    const CACHE_TTL         = 21600;        // 6 hours

    /**
     * Check if the circuit breaker is OPEN for a chain.
     *
     * Returns true if the chain should be blocked (OPEN state and
     * cooldown not yet expired). Returns false for CLOSED or HALF-OPEN.
     */
    public static function isOpen(int $chainId): bool
    {
        $state = self::getState($chainId);

        if ($state === null) {
            return false; // No state → CLOSED
        }

        $failures = (int) ($state['failures'] ?? 0);
        $openedAt = (int) ($state['opened_at'] ?? 0);

        if ($failures < self::FAILURE_THRESHOLD) {
            return false; // Below threshold → CLOSED
        }

        // Tripped — check if cooldown has expired (HALF-OPEN)
        if ($openedAt > 0 && (time() - $openedAt) >= self::COOLDOWN_SECONDS) {
            return false; // Cooldown expired → HALF-OPEN, allow probe
        }

        return true; // Still in cooldown → OPEN
    }

    /**
     * Record a successful API call for a chain.
     * Resets the failure counter — circuit returns to CLOSED.
     */
    public static function recordSuccess(int $chainId): void
    {
        $state = self::getState($chainId);

        // Only write if there was a non-zero failure count to clear
        if ($state !== null && (int) ($state['failures'] ?? 0) > 0) {
            self::setState($chainId, [
                'failures'  => 0,
                'opened_at' => 0,
            ]);
        }

        // Also reset the atomic counter used by recordFailure() so the
        // two tracking mechanisms stay in sync. Without this, a success
        // would clear the state-struct but leave the wp_cache_incr
        // counter at its pre-success value, and the next failure would
        // increment from that stale value instead of 1.
        wp_cache_set('counter:' . $chainId, 0, self::CACHE_GROUP, self::CACHE_TTL);

        // Track last successful fetch time (persists across cache flushes)
        update_option('bcc_onchain_last_success_' . $chainId, time(), false);
    }

    /**
     * Identify chains with stale data (no successful fetch within $maxAgeSec).
     *
     * @param int[] $chainIds   Active chain IDs to check.
     * @param int   $maxAgeSec  Maximum acceptable age in seconds (default 48 hours).
     * @return array<int, array{last_success: int|null, age_human: string, circuit_status: string}>
     *               Keyed by chain ID. Only stale/never-fetched chains included.
     */
    public static function getStaleChains(array $chainIds, int $maxAgeSec = 172800): array
    {
        $stale = [];
        $now   = time();

        foreach ($chainIds as $id) {
            $id          = (int) $id;
            $lastSuccess = get_option('bcc_onchain_last_success_' . $id, null);

            $isStale = ($lastSuccess === null)
                || (($now - (int) $lastSuccess) > $maxAgeSec);

            if (!$isStale) {
                continue;
            }

            // Determine circuit breaker status for context
            $cbState  = self::getState($id);
            $failures = (int) ($cbState['failures'] ?? 0);
            $openedAt = (int) ($cbState['opened_at'] ?? 0);

            if ($failures >= self::FAILURE_THRESHOLD) {
                $elapsed       = $now - $openedAt;
                $circuitStatus = $elapsed >= self::COOLDOWN_SECONDS ? 'HALF-OPEN' : 'OPEN';
            } else {
                $circuitStatus = 'CLOSED';
            }

            // Human-readable age
            if ($lastSuccess === null) {
                $ageHuman = 'never fetched';
            } else {
                $ageSec   = $now - (int) $lastSuccess;
                $ageDays  = floor($ageSec / DAY_IN_SECONDS);
                $ageHours = floor(($ageSec % DAY_IN_SECONDS) / HOUR_IN_SECONDS);
                if ($ageDays > 0) {
                    $ageHuman = sprintf('last success: %d day%s ago', $ageDays, (int) $ageDays === 1 ? '' : 's');
                } else {
                    $ageHuman = sprintf('last success: %d hour%s ago', $ageHours, (int) $ageHours === 1 ? '' : 's');
                }
            }

            $stale[$id] = [
                'last_success'   => $lastSuccess !== null ? (int) $lastSuccess : null,
                'age_human'      => $ageHuman,
                'circuit_status' => $circuitStatus,
            ];
        }

        return $stale;
    }

    /**
     * Record a failed API call for a chain.
     * Increments failure counter. If threshold reached, records opened_at.
     */
    public static function recordFailure(int $chainId): void
    {
        // Atomic counter via wp_cache_incr — closes the get→compute→set
        // lost-update race. Under a parallel failure storm two workers
        // could both read failures=4 and both write failures=5, missing
        // an increment; the circuit stayed closed longer than designed,
        // burning more API budget (and risking IP bans from providers).
        $counterKey = 'counter:' . $chainId;

        // Ensure the counter exists before incrementing; wp_cache_incr
        // returns false on a missing key in some drop-ins.
        wp_cache_add($counterKey, 0, self::CACHE_GROUP, self::CACHE_TTL);
        $failures = wp_cache_incr($counterKey, 1, self::CACHE_GROUP);
        if (!is_int($failures) || $failures <= 0) {
            // Backend degraded — fall back to read-modify-write so we at
            // least record SOMETHING. Still better than silently dropping
            // the failure signal.
            $state    = self::getState($chainId) ?? ['failures' => 0, 'opened_at' => 0];
            $failures = (int) ($state['failures'] ?? 0) + 1;
        }

        $state    = self::getState($chainId) ?? ['failures' => 0, 'opened_at' => 0];
        $openedAt = (int) ($state['opened_at'] ?? 0);
        if ($failures >= self::FAILURE_THRESHOLD && $openedAt === 0) {
            $openedAt = time();
            self::log(sprintf(
                'Circuit OPEN for chain %d — %d consecutive failures, pausing for %ds',
                $chainId, $failures, self::COOLDOWN_SECONDS
            ));
        }

        self::setState($chainId, [
            'failures'  => $failures,
            'opened_at' => $openedAt,
        ]);
    }

    /**
     * Get circuit breaker status for all active chains (admin dashboard).
     *
     * @param int[] $chainIds
     * @return array<int, array{failures: int, opened_at: int, status: string}>
     */
    public static function getAllStatus(array $chainIds): array
    {
        $result = [];
        foreach ($chainIds as $id) {
            $state    = self::getState((int) $id);
            $failures = (int) ($state['failures'] ?? 0);
            $openedAt = (int) ($state['opened_at'] ?? 0);

            if ($failures >= self::FAILURE_THRESHOLD) {
                $elapsed = time() - $openedAt;
                $status  = $elapsed >= self::COOLDOWN_SECONDS ? 'half-open' : 'open';
            } else {
                $status = 'closed';
            }

            $result[(int) $id] = [
                'failures'  => $failures,
                'opened_at' => $openedAt,
                'status'    => $status,
            ];
        }
        return $result;
    }

    // ── Storage ─────────────────────────────────────────────────────────────

    /**
     * @return array{failures: int, opened_at: int}|null
     *
     * NOTE: when the cached value is present but malformed (schema drift,
     * mid-deploy legacy keys, cache layer corruption) we return null which
     * re-initialises the breaker. If that happens repeatedly for the SAME
     * chain, the breaker is effectively disabled. We log once per 5-minute
     * window per chain so repeated corruption surfaces in monitoring instead
     * of silently eroding the protection.
     */
    private static function getState(int $chainId): ?array
    {
        $key   = 'cb_' . $chainId;
        $value = wp_cache_get($key, self::CACHE_GROUP);

        if ($value === false) {
            // Fallback to transient if Redis cache misses
            $value = get_transient('bcc_cb_' . $chainId);
            if ($value === false) {
                return null;
            }
        }

        if (!is_array($value) || !isset($value['failures'], $value['opened_at'])) {
            self::reportPersistentCorruption($chainId);
            return null;
        }

        return [
            'failures'  => (int) $value['failures'],
            'opened_at' => (int) $value['opened_at'],
        ];
    }

    /**
     * Rate-limited logger for malformed breaker state. Fires at most once per
     * 5-minute window per chain to avoid log flooding while still surfacing
     * persistent corruption patterns (e.g. a bad cache backend or a stuck
     * legacy key) to operators.
     *
     * CAVEAT — multi-node reliability: the dedup transient lives in whatever
     * backend WordPress is configured with. On a single node with Redis it is
     * consistent; on a multi-node setup WITHOUT a shared persistent object
     * cache, each node will log independently (their dedup transients are in
     * separate options tables). That's acceptable for this signal — the goal is
     * to surface the problem, and per-node logging actually HELPS diagnose
     * whether only one node's cache is corrupt. If this ever needs true global
     * dedup, move the key to a row in a small shared table.
     */
    private static function reportPersistentCorruption(int $chainId): void
    {
        $dedupKey = 'cb_corrupt_' . $chainId;
        if (get_transient($dedupKey) !== false) {
            return;
        }
        set_transient($dedupKey, 1, 5 * MINUTE_IN_SECONDS);

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[CircuitBreaker] malformed state — re-initialising', [
                'chain_id' => $chainId,
                'note'     => 'Repeated re-inits weaken protection. Investigate cache backend.',
            ]);
        }
    }

    /** @param array{failures: int, opened_at: int} $state */
    private static function setState(int $chainId, array $state): void
    {
        $key = 'cb_' . $chainId;
        wp_cache_set($key, $state, self::CACHE_GROUP, self::CACHE_TTL);
        // Transient fallback for environments without persistent object cache
        set_transient('bcc_cb_' . $chainId, $state, self::CACHE_TTL);
    }

    private static function log(string $message): void
    {
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[CircuitBreaker] ' . $message);
        }
    }
}
