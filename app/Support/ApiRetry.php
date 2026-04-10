<?php
/**
 * API Retry Helper
 *
 * Wraps wp_remote_get/post with retry logic, exponential backoff,
 * and 429 rate-limit handling. Every external HTTP call in the plugin
 * should go through this class to ensure resilient API consumption.
 *
 * Retry policy:
 *   - Retries on: timeouts, network errors (WP_Error), 5xx, 429
 *   - Does NOT retry on: 4xx (except 429)
 *   - Backoff: exponential (2s, 5s, 15s) with optional jitter
 *   - 429: respects Retry-After header, falls back to 15s
 *
 * @package BCC\Onchain\Support
 */

namespace BCC\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class ApiRetry
{
    // ── Defaults ────────────────────────────────────────────────────────────
    const DEFAULT_MAX_RETRIES   = 3;
    const DEFAULT_BACKOFF_BASE  = 2;      // seconds
    const DEFAULT_BACKOFF_MAX   = 30;     // seconds
    const DEFAULT_429_FALLBACK  = 15;     // seconds if no Retry-After header
    const BACKOFF_MULTIPLIER    = 2.5;    // 2s → 5s → 12.5s → 30s (capped)

    /**
     * Execute an HTTP request with automatic retry on transient failures.
     *
     * @param callable $fn        Must return a WP HTTP response array or WP_Error.
     *                            Signature: fn(): array|WP_Error
     * @param array    $options   {
     *     @type int    $max_retries   Max retry attempts (default 3).
     *     @type string $label         Human-readable label for logging (e.g. "Cosmos LCD /validators").
     *     @type int    $chain_id      Chain ID for circuit breaker integration.
     * }
     * @return array|WP_Error  The final HTTP response or WP_Error after all retries exhausted.
     */
    public static function request(callable $fn, array $options = [])
    {
        $maxRetries = (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES);
        $label      = $options['label'] ?? 'API call';
        $chainId    = (int) ($options['chain_id'] ?? 0);

        // Circuit breaker: check before attempting
        if ($chainId > 0 && CircuitBreaker::isOpen($chainId)) {
            self::log("BLOCKED by circuit breaker: {$label} (chain {$chainId})");
            return new \WP_Error('circuit_breaker_open', "Circuit breaker open for chain {$chainId}");
        }

        // Per-chain budget: check before attempting
        if ($chainId > 0
            && class_exists('\\BCC\\Onchain\\Services\\EnrichmentScheduler')
            && \BCC\Onchain\Services\EnrichmentScheduler::isChainBudgetExceeded($chainId)
        ) {
            self::log("BLOCKED by chain budget: {$label} (chain {$chainId})");
            return new \WP_Error('chain_budget_exceeded', "API budget exceeded for chain {$chainId}");
        }

        $attempt     = 0;
        $lastResponse = null;

        while ($attempt <= $maxRetries) {
            $lastResponse = $fn();

            // ── Success path ────────────────────────────────────────────
            if (!is_wp_error($lastResponse)) {
                $code = (int) wp_remote_retrieve_response_code($lastResponse);

                if ($code >= 200 && $code < 300) {
                    // Success — record for circuit breaker
                    if ($chainId > 0) {
                        CircuitBreaker::recordSuccess($chainId);
                    }
                    // Track API call against enrichment budget (all fetchers).
                    if ($chainId > 0 && class_exists('\\BCC\\Onchain\\Services\\EnrichmentScheduler')) {
                        \BCC\Onchain\Services\EnrichmentScheduler::trackApiCall($chainId);
                    }
                    return $lastResponse;
                }

                // ── 429 Rate Limited ────────────────────────────────────
                if ($code === 429) {
                    $delay = self::parseRetryAfter($lastResponse);
                    self::log(sprintf(
                        'RATE LIMITED (429) %s — attempt %d/%d, waiting %ds',
                        $label, $attempt + 1, $maxRetries + 1, $delay
                    ));

                    if ($chainId > 0) {
                        CircuitBreaker::recordFailure($chainId);
                    }

                    // Do NOT sleep — return immediately and let the caller
                    // (EnrichmentScheduler) decide whether to skip this chain.
                    // Sleeping in a cron loop can exceed PHP max_execution_time.
                    return $lastResponse;
                }

                // ── 5xx Server Error — retryable with short delay ───────
                if ($code >= 500) {
                    self::log(sprintf(
                        'SERVER ERROR (%d) %s — attempt %d/%d',
                        $code, $label, $attempt + 1, $maxRetries + 1
                    ));

                    if ($chainId > 0) {
                        CircuitBreaker::recordFailure($chainId);
                    }

                    if ($attempt < $maxRetries) {
                        // Cap at 2s to prevent cron timeout; circuit breaker
                        // handles longer outages at the chain level.
                        sleep(min(2, self::backoffDelay($attempt)));
                        $attempt++;
                        continue;
                    }
                    return $lastResponse;
                }

                // ── 4xx Client Error (not 429) — NOT retryable ──────────
                if ($code >= 400) {
                    self::log(sprintf(
                        'CLIENT ERROR (%d) %s — not retrying',
                        $code, $label
                    ));
                    return $lastResponse;
                }

                // Other codes (3xx, etc.) — return as-is
                return $lastResponse;
            }

            // ── WP_Error (timeout, DNS, connection refused) — retryable ─
            $errorMsg = $lastResponse->get_error_message();
            self::log(sprintf(
                'NETWORK ERROR %s — attempt %d/%d: %s',
                $label, $attempt + 1, $maxRetries + 1, $errorMsg
            ));

            if ($chainId > 0) {
                CircuitBreaker::recordFailure($chainId);
            }

            if ($attempt < $maxRetries) {
                sleep(min(2, self::backoffDelay($attempt)));
                $attempt++;
                continue;
            }

            break;
        }

        return $lastResponse;
    }

    /**
     * Convenience: wp_remote_get with retry.
     *
     * @param string $url     Request URL.
     * @param array  $args    wp_remote_get args (timeout, headers, etc.).
     * @param array  $options ApiRetry options (max_retries, label, chain_id).
     * @return array|WP_Error
     */
    public static function get(string $url, array $args = [], array $options = [])
    {
        return self::request(
            fn() => wp_remote_get($url, $args),
            $options
        );
    }

    /**
     * Convenience: wp_remote_post with retry.
     *
     * @param string $url     Request URL.
     * @param array  $args    wp_remote_post args (timeout, headers, body, etc.).
     * @param array  $options ApiRetry options (max_retries, label, chain_id).
     * @return array|WP_Error
     */
    public static function post(string $url, array $args = [], array $options = [])
    {
        return self::request(
            fn() => wp_remote_post($url, $args),
            $options
        );
    }

    // ── Internal ────────────────────────────────────────────────────────────

    /**
     * Calculate backoff delay for a given attempt number.
     * Formula: min(BACKOFF_MAX, BACKOFF_BASE * MULTIPLIER^attempt)
     *
     * Attempt 0 → 2s
     * Attempt 1 → 5s
     * Attempt 2 → 12.5s → capped at 30s
     */
    private static function backoffDelay(int $attempt): int
    {
        $delay = self::DEFAULT_BACKOFF_BASE * pow(self::BACKOFF_MULTIPLIER, $attempt);
        return (int) min(self::DEFAULT_BACKOFF_MAX, $delay);
    }

    /**
     * Parse the Retry-After header from a 429 response.
     * Returns seconds to wait. Falls back to DEFAULT_429_FALLBACK.
     */
    private static function parseRetryAfter($response): int
    {
        $header = wp_remote_retrieve_header($response, 'retry-after');

        if ($header === '' || $header === false) {
            return self::DEFAULT_429_FALLBACK;
        }

        // Retry-After can be seconds (integer) or HTTP-date.
        if (is_numeric($header)) {
            return max(1, min(120, (int) $header));  // Cap at 2 minutes
        }

        // Try parsing as HTTP-date
        $timestamp = strtotime($header);
        if ($timestamp !== false && $timestamp > time()) {
            return min(120, $timestamp - time());
        }

        return self::DEFAULT_429_FALLBACK;
    }

    private static function log(string $message): void
    {
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[ApiRetry] ' . $message);
        }
    }
}
