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
     * @param array<string, mixed> $options   {
     *     @type int    $max_retries   Max retry attempts (default 3).
     *     @type string $label         Human-readable label for logging (e.g. "Cosmos LCD /validators").
     *     @type int    $chain_id      Chain ID for circuit breaker integration.
     * }
     * @return array<string, mixed>|\WP_Error  The final HTTP response or WP_Error after all retries exhausted.
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
                        // Use full exponential backoff for 5xx errors.
                        // The 2s cap was causing premature circuit-breaker trips
                        // on APIs that need longer recovery windows.
                        $delay = min(self::DEFAULT_BACKOFF_MAX, self::backoffDelay($attempt));
                        sleep($delay);
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
                $delay = min(self::DEFAULT_BACKOFF_MAX, self::backoffDelay($attempt));
                sleep($delay);
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
     * @param array<string, mixed>  $args    wp_remote_get args (timeout, headers, etc.).
     * @param array<string, mixed>  $options ApiRetry options (max_retries, label, chain_id).
     * @return array<string, mixed>|\WP_Error
     */
    public static function get(string $url, array $args = [], array $options = [])
    {
        $pinResult = self::validateAndPinUrl($url);
        if ($pinResult instanceof \WP_Error) {
            return $pinResult;
        }

        // Pin the resolved IP so cURL cannot re-resolve to a different address.
        if ($pinResult !== null) {
            $args = self::injectCurlResolve($args, $pinResult['host'], $pinResult['port'], $pinResult['ip']);
        }

        // Disable redirects: each hop could target a private IP, bypassing
        // our DNS pinning. Chain APIs should not redirect. If one does, the
        // caller must handle it explicitly with a fresh validateAndPinUrl().
        $args['redirection'] = 0;

        return self::request(
            fn() => wp_remote_get($url, $args),
            $options
        );
    }

    /**
     * Convenience: wp_remote_post with retry.
     *
     * @param string $url     Request URL.
     * @param array<string, mixed>  $args    wp_remote_post args (timeout, headers, body, etc.).
     * @param array<string, mixed>  $options ApiRetry options (max_retries, label, chain_id).
     * @return array<string, mixed>|\WP_Error
     */
    public static function post(string $url, array $args = [], array $options = [])
    {
        $pinResult = self::validateAndPinUrl($url);
        if ($pinResult instanceof \WP_Error) {
            return $pinResult;
        }

        if ($pinResult !== null) {
            $args = self::injectCurlResolve($args, $pinResult['host'], $pinResult['port'], $pinResult['ip']);
        }

        $args['redirection'] = 0;

        return self::request(
            fn() => wp_remote_post($url, $args),
            $options
        );
    }

    // ── SSRF Protection ────────────────────────────────────────────────────

    /**
     * Validate a URL and resolve its IP for pinning.
     *
     * Every URL — including hardcoded ones — is validated. No safe-host
     * shortcuts: attackers can poison DNS for any domain.
     *
     * Returns:
     *   - WP_Error if the URL is blocked (private IP, invalid, etc.)
     *   - null if the host is already an IP literal (no pinning needed)
     *   - array{host: string, port: int, ip: string} for hostname-based
     *     URLs so the caller can pin via CURLOPT_RESOLVE
     *
     * @return \WP_Error|array{host: string, port: int, ip: string}|null
     */
    private static function validateAndPinUrl(string $url)
    {
        $parsed = parse_url($url);
        if (!is_array($parsed) || !isset($parsed['host'])) {
            return new \WP_Error('ssrf_invalid_url', 'Invalid URL: missing host');
        }

        $host   = $parsed['host'];
        $scheme = $parsed['scheme'] ?? '';

        if (!in_array($scheme, ['http', 'https'], true)) {
            return new \WP_Error('ssrf_invalid_scheme', 'Only HTTP(S) URLs are allowed');
        }

        // Block cloud metadata endpoints by hostname before DNS resolution.
        $blockedHosts = ['metadata.google.internal', 'metadata.google.com'];
        if (in_array(strtolower($host), $blockedHosts, true)) {
            self::log("SSRF BLOCKED: metadata endpoint {$host}");
            return new \WP_Error('ssrf_blocked', 'Blocked request to cloud metadata endpoint');
        }

        // If host is already an IP literal, validate it directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                self::log("SSRF BLOCKED: direct IP {$host} is private/reserved");
                return new \WP_Error('ssrf_blocked', "Blocked request to private/reserved IP: {$host}");
            }
            return null; // IP literal — no DNS to pin.
        }

        // ── Collect ALL resolved IPs (A + AAAA), validate, pin ────────
        // Strategy: gather every IP the host resolves to, discard any
        // private/reserved addresses, then pin the first valid public IP.
        // If NO public IPs remain, block the request entirely.
        // This prevents mixed-record attacks where one valid A record
        // passes validation but cURL picks a private AAAA record.
        /** @var string[] $validPublicIps */
        $validPublicIps = [];
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        // Collect IPv4 (A records) via gethostbyname.
        $ipv4 = gethostbyname($host);
        if ($ipv4 !== $host && filter_var($ipv4, FILTER_VALIDATE_IP, $flags)) {
            $validPublicIps[] = $ipv4;
        }

        // Collect IPv6 (AAAA records) via dns_get_record.
        $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                $ipv6 = $record['ipv6'] ?? '';
                if ($ipv6 !== '' && filter_var($ipv6, FILTER_VALIDATE_IP, $flags)) {
                    $validPublicIps[] = $ipv6;
                }
            }
        }

        // If no public IPs found, block.
        if (empty($validPublicIps)) {
            self::log("SSRF BLOCKED: {$host} has no public IPs (all private/reserved or DNS failed)");
            return new \WP_Error('ssrf_blocked', "Blocked: {$host} resolves to no public IP addresses");
        }

        // Pin the first valid public IP. Prefer IPv4 for compatibility.
        $pinnedIp = $validPublicIps[0];
        $port     = (int) ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));

        return ['host' => $host, 'port' => $port, 'ip' => $pinnedIp];
    }

    /**
     * Pinned DNS entries: host → "host:port:ip" for CURLOPT_RESOLVE.
     *
     * Populated by validateAndPinUrl(), consumed by the http_api_curl hook.
     * Entries are per-request (PHP is single-threaded) and cleared after use.
     *
     * @var array<string, string>
     */
    private static array $pinnedResolves = [];

    /** Whether the http_api_curl hook has been registered. */
    private static bool $hookRegistered = false;

    /**
     * Pin a hostname to a resolved IP so cURL cannot re-resolve DNS.
     *
     * Sets CURLOPT_RESOLVE via WordPress's http_api_curl action hook.
     * The Host header remains the original hostname (TLS SNI + vhosts),
     * but the TCP connection goes to the pinned IP.
     *
     * @param array<string, mixed> $args  WP HTTP API args (returned unchanged).
     * @param string $host  Original hostname.
     * @param int    $port  Target port.
     * @param string $ip    Validated IP address.
     * @return array<string, mixed>
     */
    private static function injectCurlResolve(array $args, string $host, int $port, string $ip): array
    {
        self::$pinnedResolves[$host] = "{$host}:{$port}:{$ip}";

        if (!self::$hookRegistered) {
            add_action('http_api_curl', [self::class, 'applyCurlResolve'], 99, 3);
            self::$hookRegistered = true;
        }

        return $args;
    }

    /**
     * WordPress http_api_curl hook: apply pinned DNS entries.
     *
     * @param resource|\CurlHandle $handle     cURL handle.
     * @param array<string, mixed> $parsedArgs Parsed HTTP args.
     * @param string               $url        Request URL.
     */
    public static function applyCurlResolve(&$handle, array $parsedArgs, string $url): void
    {
        if (empty(self::$pinnedResolves)) {
            return;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host !== '' && isset(self::$pinnedResolves[$host])) {
            curl_setopt($handle, CURLOPT_RESOLVE, [self::$pinnedResolves[$host]]);
            // Clear after use — one pin per request cycle.
            unset(self::$pinnedResolves[$host]);
        }
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
     *
     * @param array<string, mixed>|\WP_Error $response
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
