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
    }

    // ── Validator Refresh ────────────────────────────────────────────────────

    public static function refresh_validators(): void
    {
        $expired = ValidatorRepository::getExpired(self::BATCH_SIZE);

        if (empty($expired)) {
            return;
        }

        foreach ($expired as $row) {
            try {
                $chain = ChainRepository::getById((int) $row->chain_id);
                if (!$chain || !FetcherFactory::has_driver($chain->chain_type)) {
                    continue;
                }

                $fetcher = FetcherFactory::make_for_chain($chain);

                if (!$fetcher->supports_feature('validator')) {
                    continue;
                }

                $data = $fetcher->fetch_validator($row->operator_address);

                if (!empty($data)) {
                    ValidatorRepository::upsert($data, (int) $row->wallet_link_id, HOUR_IN_SECONDS);
                }
            } catch (\Exception $e) {
                error_log("BCC Refresh: Validator {$row->operator_address} failed — " . $e->getMessage());
                self::backoffRow(ValidatorRepository::table(), (int) $row->id);
            }
        }
    }

    // ── Collection Refresh ──────────────────────────────────────────────────

    public static function refresh_collections(): void
    {
        $expired = CollectionRepository::getExpired(self::BATCH_SIZE);

        if (empty($expired)) {
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

                foreach ($collections as $collection) {
                    CollectionRepository::upsert($collection, (int) $row->wallet_link_id, 4 * HOUR_IN_SECONDS);
                }

                if (!empty($collections) && (int) $wallet->post_id > 0) {
                    CollectionService::invalidate((int) $wallet->post_id);
                }
            } catch (\Exception $e) {
                error_log("BCC Refresh: Collection {$row->contract_address} failed — " . $e->getMessage());
                self::backoffRow(CollectionRepository::table(), (int) $row->id);
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Exponential backoff: push expires_at forward by 2x the original TTL.
     */
    private static function backoffRow(string $table, int $row_id): void
    {
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
