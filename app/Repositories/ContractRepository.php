<?php

namespace BCC\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class ContractRepository
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_onchain_contracts';
    }

    /**
     * @return array{items: array, total: int, pages: int}
     */
    public static function getForProject(int $postId, int $page = 1, int $perPage = 8, string $orderBy = 'contract_name'): array
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        $allowedOrder = ['contract_name', 'contract_type', 'chain_id', 'is_verified'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'contract_name';
        }

        $offset = ($page - 1) * $perPage;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} ct JOIN {$wallets} w ON w.id = ct.wallet_link_id WHERE w.post_id = %d",
            $postId
        ));

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT ct.*, ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url
             FROM {$table} ct
             JOIN {$wallets} w ON w.id = ct.wallet_link_id
             JOIN {$chains} ch ON ch.id = ct.chain_id
             WHERE w.post_id = %d
             ORDER BY ct.{$orderBy} ASC
             LIMIT %d OFFSET %d",
            $postId, $perPage, $offset
        ));

        return ['items' => $items ?: [], 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    public static function getExpired(int $limit = 50): array
    {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE expires_at < NOW() ORDER BY expires_at ASC LIMIT %d",
            $limit
        ));
    }
}
