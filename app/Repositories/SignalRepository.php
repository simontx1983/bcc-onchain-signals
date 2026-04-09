<?php

namespace BCC\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\DB\DB;
use BCC\Core\PeepSo\PeepSo;

class SignalRepository
{
    /** @var string Explicit column list — must match schema (install_own_table). */
    private const COLUMNS = 'id, user_id, wallet_address, chain, wallet_age_days, first_tx_at,
                 tx_count, contract_count, score_contribution, raw_data, fetched_at';

    /** @var string Object-cache group. */
    private const CACHE_GROUP = 'bcc_onchain_signals';

    /** @var int Default TTL in seconds (6 hours). Filterable via bcc_onchain_signal_cache_ttl. */
    private const DEFAULT_TTL = 6 * HOUR_IN_SECONDS;

    public static function table(): string
    {
        return DB::table('onchain_signals');
    }

    /**
     * Create this plugin's own table. No BCC Core dependency.
     */
    public static function install_own_table(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = self::table();

        $sql = "CREATE TABLE {$table} (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id          BIGINT UNSIGNED NOT NULL,
            wallet_address   VARCHAR(255)    NOT NULL,
            chain            VARCHAR(20)     NOT NULL,
            wallet_age_days  INT UNSIGNED    NOT NULL DEFAULT 0,
            first_tx_at      DATETIME                 DEFAULT NULL,
            tx_count         INT UNSIGNED    NOT NULL DEFAULT 0,
            contract_count   INT UNSIGNED    NOT NULL DEFAULT 0,
            score_contribution FLOAT         NOT NULL DEFAULT 0,
            raw_data         LONGTEXT                 DEFAULT NULL,
            fetched_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wallet_chain (wallet_address(191), chain),
            INDEX idx_user (user_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('bcc_onchain_db_version', BCC_ONCHAIN_VERSION);
    }

    public static function upsert(array $data): void
    {
        global $wpdb;
        $table = self::table();

        // VALUES() is deprecated in MySQL 8.0.20+ but MariaDB does not support
        // the replacement AS-alias syntax. Since WordPress supports both MySQL
        // and MariaDB, VALUES() remains the only portable option.
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (user_id, wallet_address, chain, wallet_age_days, first_tx_at, tx_count, contract_count, score_contribution, raw_data, fetched_at)
             VALUES (%d, %s, %s, %d, %s, %d, %d, %f, %s, NOW())
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                wallet_age_days = VALUES(wallet_age_days),
                first_tx_at = VALUES(first_tx_at),
                tx_count = VALUES(tx_count),
                contract_count = VALUES(contract_count),
                score_contribution = VALUES(score_contribution),
                raw_data = VALUES(raw_data),
                fetched_at = NOW()",
            $data['user_id'],
            $data['wallet_address'],
            $data['chain'],
            $data['wallet_age_days'],
            $data['first_tx_at'] ?? null,
            $data['tx_count'],
            $data['contract_count'],
            $data['score_contribution'],
            isset($data['raw_data']) ? wp_json_encode($data['raw_data']) : null
        ));

        if ($result === false) {
            \BCC\Core\Log\Logger::error('[Onchain] Upsert failed for ' . $data['wallet_address'] . ' on ' . $data['chain'] . ': ' . $wpdb->last_error);
        } else {
            self::invalidateUser((int) $data['user_id']);
        }
    }

    /**
     * Return stored data if fetched within BCC_ONCHAIN_CACHE_HOURS.
     */
    public static function get_cached(string $address, string $chain): ?array
    {
        global $wpdb;
        $table   = self::table();
        $max_age = BCC_ONCHAIN_CACHE_HOURS;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table}
             WHERE wallet_address = %s AND chain = %s
               AND fetched_at > DATE_SUB(NOW(), INTERVAL %d HOUR)
             LIMIT 1",
            $address, $chain, $max_age
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Return stored data regardless of age.
     */
    public static function get_permanent(string $address, string $chain): ?array
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table} WHERE wallet_address = %s AND chain = %s LIMIT 1",
            $address, $chain
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Get all on-chain signals for all wallets belonging to the page owner.
     *
     * Results are served from object cache (per-request, persistent with Redis)
     * backed by a transient (cross-request on vanilla WP). The cache is keyed
     * by owner user_id and invalidated automatically on upsert().
     */
    public static function get_for_page(int $page_id): array
    {
        $owner_id = PeepSo::get_page_owner($page_id);
        if (!$owner_id) {
            return [];
        }

        return self::getByUser($owner_id);
    }

    /**
     * Get all signals for a user, with two-tier caching.
     */
    public static function getByUser(int $userId): array
    {
        $cache_key     = 'signals_user_' . $userId;
        $transient_key = 'bcc_signals_u' . $userId;

        // 1. Object cache (fastest).
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        // 2. Transient (cross-request fallback without persistent object cache).
        $transient = get_transient($transient_key);
        if (is_array($transient)) {
            wp_cache_set($cache_key, $transient, self::CACHE_GROUP, self::ttl());
            return $transient;
        }

        // 3. DB query on cache miss.
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT " . self::COLUMNS . " FROM {$table} WHERE user_id = %d ORDER BY score_contribution DESC", $userId),
            ARRAY_A
        ) ?: [];

        $ttl = self::ttl();
        wp_cache_set($cache_key, $rows, self::CACHE_GROUP, $ttl);
        set_transient($transient_key, $rows, $ttl);

        return $rows;
    }

    /**
     * Invalidate cached signals for a user.
     *
     * Called automatically from upsert(). Also safe to call manually
     * from cron handlers or admin tools.
     */
    public static function invalidateUser(int $userId): void
    {
        wp_cache_delete('signals_user_' . $userId, self::CACHE_GROUP);
        delete_transient('bcc_signals_u' . $userId);
    }

    private static function ttl(): int
    {
        return (int) apply_filters('bcc_onchain_signal_cache_ttl', self::DEFAULT_TTL);
    }
}
