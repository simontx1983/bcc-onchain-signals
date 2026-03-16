<?php
/**
 * Chain Refresh Cron
 *
 * Separate cron jobs per data type, each with its own interval.
 * Uses Action Scheduler if available, falls back to WP Cron.
 *
 * Jobs:
 *  - bcc_refresh_validators   (every 1 hour)
 *  - bcc_refresh_collections  (every 4 hours)
 *  - bcc_refresh_dao          (every 6 hours)
 *  - bcc_refresh_contracts    (every 12 hours)
 *
 * @package BCC_Onchain_Signals
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Chain_Refresh {

    const BATCH_SIZE = 50;

    /**
     * Register cron hooks.
     */
    public static function init(): void {
        // Register custom cron intervals on every request so wp-cron.php can resolve them
        add_filter('cron_schedules', [__CLASS__, 'add_cron_intervals']);

        // Register cron actions
        add_action('bcc_refresh_validators',  [__CLASS__, 'refresh_validators']);
        add_action('bcc_refresh_collections', [__CLASS__, 'refresh_collections']);
        add_action('bcc_refresh_dao',         [__CLASS__, 'refresh_dao']);
        add_action('bcc_refresh_contracts',   [__CLASS__, 'refresh_contracts']);

        // Schedule on admin_init if not already scheduled
        add_action('admin_init', [__CLASS__, 'schedule_crons']);
    }

    /**
     * Register custom cron intervals.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public static function add_cron_intervals(array $schedules): array {
        $schedules['every_4_hours'] = [
            'interval' => 4 * HOUR_IN_SECONDS,
            'display'  => 'Every 4 Hours',
        ];
        $schedules['every_6_hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => 'Every 6 Hours',
        ];
        $schedules['every_12_hours'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => 'Every 12 Hours',
        ];
        return $schedules;
    }

    /**
     * Schedule recurring cron jobs.
     */
    public static function schedule_crons(): void {
        // Schedule if not already running
        $jobs = [
            'bcc_refresh_validators'  => 'hourly',
            'bcc_refresh_collections' => 'every_4_hours',
            'bcc_refresh_dao'         => 'every_6_hours',
            'bcc_refresh_contracts'   => 'every_12_hours',
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
    public static function deactivate(): void {
        wp_clear_scheduled_hook('bcc_refresh_validators');
        wp_clear_scheduled_hook('bcc_refresh_collections');
        wp_clear_scheduled_hook('bcc_refresh_dao');
        wp_clear_scheduled_hook('bcc_refresh_contracts');
    }

    // ── Validator Refresh ────────────────────────────────────────────────────

    public static function refresh_validators(): void {
        $expired = bcc_onchain_get_expired_validators(self::BATCH_SIZE);

        if (empty($expired)) {
            return;
        }

        foreach ($expired as $row) {
            try {
                $chain = bcc_onchain_get_chain_by_id((int) $row->chain_id);
                if (!$chain || !BCC_Fetcher_Factory::has_driver($chain->chain_type)) {
                    continue;
                }

                $fetcher = BCC_Fetcher_Factory::make_for_chain($chain);

                if (!$fetcher->supports_feature('validator')) {
                    continue;
                }

                $data = $fetcher->fetch_validator($row->operator_address);

                if (!empty($data)) {
                    bcc_onchain_upsert_validator($data, (int) $row->wallet_link_id, HOUR_IN_SECONDS);
                }
            } catch (\Exception $e) {
                error_log("BCC Refresh: Validator {$row->operator_address} failed — " . $e->getMessage());
                // Exponential backoff: extend expires_at by doubling remaining TTL
                self::backoff_row(bcc_onchain_validators_table(), (int) $row->id);
            }
        }
    }

    // ── Collection Refresh (stub) ────────────────────────────────────────────

    public static function refresh_collections(): void {
        // TODO: Implement when EVM fetcher is ready
    }

    // ── DAO Refresh (stub) ───────────────────────────────────────────────────

    public static function refresh_dao(): void {
        // TODO: Implement when DAO tables are ready
    }

    // ── Contract Refresh (stub) ──────────────────────────────────────────────

    public static function refresh_contracts(): void {
        // TODO: Implement when contract tables are ready
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Exponential backoff: push expires_at forward by 2x the original TTL.
     * Prevents hammering failed endpoints.
     *
     * Table names cannot be parameterized with $wpdb->prepare(), so we
     * validate against a prefix allowlist before interpolation.
     */
    private static function backoff_row(string $table, int $row_id): void {
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

BCC_Chain_Refresh::init();
