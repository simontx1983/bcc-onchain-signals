<?php

namespace BCC\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

final class ChainRepository
{
    /** @var string Object-cache / transient group. */
    private const CACHE_GROUP = 'bcc_chains';

    /** @var int Default TTL in seconds (1 hour). Filterable via bcc_chains_cache_ttl. */
    private const DEFAULT_TTL = HOUR_IN_SECONDS;

    public static function table(): string
    {
        return DB::table('chains');
    }

    public static function getBySlug(string $slug): ?object
    {
        // Lookup from the cached active-chains set first.
        foreach (self::getActive() as $chain) {
            if ($chain->slug === $slug) {
                return $chain;
            }
        }

        return null;
    }

    public static function getById(int $chainId): ?object
    {
        // Check the cached active set.
        foreach (self::getAllCached() as $chain) {
            if ((int) $chain->id === $chainId) {
                return $chain;
            }
        }

        // Fallback: inactive chain or cache miss — direct query.
        global $wpdb;
        $table = self::table();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
            $chainId
        ));
    }

    public static function getActive(?string $chainType = null): array
    {
        $all = self::getAllCached();

        if ($chainType) {
            return array_values(array_filter(
                $all,
                fn($c) => $c->chain_type === $chainType
            ));
        }

        return $all;
    }

    public static function resolveId(string $slug): ?int
    {
        $chain = self::getBySlug($slug);
        return $chain ? (int) $chain->id : null;
    }

    // ──────────────────────────────────────────────────────────
    //  Internal
    // ──────────────────────────────────────────────────────────

    /**
     * Return all active chains, served from object cache (per-request)
     * backed by a transient (cross-request, works without Redis).
     */
    private static function getAllCached(): array
    {
        // 1. Object cache (fastest — lives for the current request / persistent if Redis is present).
        $cached = wp_cache_get('active_all', self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        // 2. Transient (survives across requests even without a persistent object cache).
        $transient = get_transient('bcc_active_chains');
        if (is_array($transient)) {
            // Re-populate object cache so the rest of this request is free.
            wp_cache_set('active_all', $transient, self::CACHE_GROUP, self::ttl());
            return $transient;
        }

        // 3. Cache miss — query the DB.
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY chain_type ASC, name ASC"
        );

        $rows = is_array($rows) ? $rows : [];

        $ttl = self::ttl();
        wp_cache_set('active_all', $rows, self::CACHE_GROUP, $ttl);
        set_transient('bcc_active_chains', $rows, $ttl);

        return $rows;
    }

    private static function ttl(): int
    {
        return (int) apply_filters('bcc_chains_cache_ttl', self::DEFAULT_TTL);
    }
}
