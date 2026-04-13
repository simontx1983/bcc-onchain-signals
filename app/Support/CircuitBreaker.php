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
    const CACHE_TTL         = 1800;         // 30 min TTL (auto-expire stale state)

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
    }

    /**
     * Record a failed API call for a chain.
     * Increments failure counter. If threshold reached, records opened_at.
     */
    public static function recordFailure(int $chainId): void
    {
        $state    = self::getState($chainId) ?? ['failures' => 0, 'opened_at' => 0];
        $failures = (int) ($state['failures'] ?? 0) + 1;

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

    /** @return array{failures: int, opened_at: int}|null */
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

        return is_array($value) ? $value : null;
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
