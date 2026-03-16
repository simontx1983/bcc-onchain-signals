<?php

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\PeepSo\PeepSo;
use BCC\Core\DB\DB;

class BCC_Onchain_DB
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_onchain_signals';
    }

    /**
     * Full install: own table + bonus column on core scores table.
     * Only safe to call when BCC Core is loaded.
     */
    public static function install(): void
    {
        self::install_own_table();
        self::install_bonus_column();
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

    /**
     * Add onchain_bonus column to the core trust_page_scores table.
     * Requires BCC Core to be loaded (uses DB::table()).
     */
    public static function install_bonus_column(): void
    {
        global $wpdb;

        if (!class_exists('BCC\\Core\\DB\\DB')) {
            return;
        }

        $scores = DB::table('trust_page_scores');
        $col    = $wpdb->get_var("SHOW COLUMNS FROM {$scores} LIKE 'onchain_bonus'");
        if (!$col) {
            $result = $wpdb->query("ALTER TABLE {$scores} ADD COLUMN onchain_bonus FLOAT NOT NULL DEFAULT 0 AFTER total_score");
            if ($result === false) {
                error_log('[BCC Onchain] ALTER TABLE failed for ' . $scores . ': ' . $wpdb->last_error);
            }
        }
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
            error_log('[BCC Onchain] Upsert failed for ' . $data['wallet_address'] . ' on ' . $data['chain'] . ': ' . $wpdb->last_error);
        }
    }

    /**
     * Return stored data if fetched within BCC_ONCHAIN_CACHE_HOURS.
     * Used by bcc_onchain_fetch_and_store() for the 24-hour refresh window.
     */
    public static function get_cached(string $address, string $chain): ?array
    {
        global $wpdb;
        $table   = self::table();
        $max_age = BCC_ONCHAIN_CACHE_HOURS;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE wallet_address = %s AND chain = %s
               AND fetched_at > DATE_SUB(NOW(), INTERVAL %d HOUR)
             LIMIT 1",
            $address, $chain, $max_age
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Return stored data regardless of age.
     * If ANY row exists for this wallet the API is never called again —
     * data persists until an admin explicitly forces a manual refresh.
     */
    public static function get_permanent(string $address, string $chain): ?array
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE wallet_address = %s AND chain = %s LIMIT 1",
            $address, $chain
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Get all on-chain signals for all wallets belonging to the page owner.
     */
    public static function get_for_page(int $page_id): array
    {
        global $wpdb;
        $table = self::table();

        // Resolve owner
        $owner_id = PeepSo::get_page_owner($page_id);
        if (!$owner_id) {
            return [];
        }

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d ORDER BY score_contribution DESC", $owner_id),
            ARRAY_A
        ) ?: [];
    }
}
