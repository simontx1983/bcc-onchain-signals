<?php

namespace BCC\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class DaoRepository
{
    public static function statsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_onchain_dao_stats';
    }

    public static function treasuryTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_onchain_treasury';
    }

    public static function getStatsForProject(int $postId): array
    {
        global $wpdb;
        $table   = self::statsTable();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, c.slug AS chain_slug, c.name AS chain_name
             FROM {$table} d
             JOIN {$wallets} w ON w.id = d.wallet_link_id
             JOIN {$chains} c ON c.id = d.chain_id
             WHERE w.post_id = %d
             ORDER BY d.total_proposals DESC",
            $postId
        )) ?: [];
    }

    public static function getTreasuryForDao(int $daoStatId): array
    {
        global $wpdb;
        $table = self::treasuryTable();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE dao_stat_id = %d ORDER BY usd_value DESC",
            $daoStatId
        )) ?: [];
    }

    public static function getExpiredStats(int $limit = 50): array
    {
        global $wpdb;
        $table = self::statsTable();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE expires_at < NOW() ORDER BY expires_at ASC LIMIT %d",
            $limit
        ));
    }
}
