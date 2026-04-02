<?php

namespace BCC\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorRepository
{
    public static function table(): string
    {
        return DB::table('onchain_validators');
    }

    /**
     * @return int|false Inserted/updated row ID, or false on failure.
     */
    public static function upsert(array $data, int $walletLinkId, int $ttlSeconds = 3600)
    {
        global $wpdb;
        $table = self::table();

        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE wallet_link_id = %d AND chain_id = %d AND operator_address = %s
             LIMIT 1",
            $walletLinkId,
            (int) $data['chain_id'],
            $data['operator_address']
        ));

        $row = [
            'wallet_link_id'           => $walletLinkId,
            'operator_address'         => $data['operator_address'],
            'chain_id'                 => (int) $data['chain_id'],
            'moniker'                  => $data['moniker'] ?? null,
            'status'                   => $data['status'] ?? 'unknown',
            'commission_rate'          => $data['commission_rate'] ?? null,
            'total_stake'              => $data['total_stake'] ?? null,
            'self_stake'               => $data['self_stake'] ?? null,
            'delegator_count'          => $data['delegator_count'] ?? null,
            'uptime_30d'               => $data['uptime_30d'] ?? null,
            'governance_participation' => $data['governance_participation'] ?? null,
            'jailed_count'             => $data['jailed_count'] ?? 0,
            'voting_power_rank'        => $data['voting_power_rank'] ?? null,
            'fetched_at'               => current_time('mysql', true),
            'expires_at'               => $expiresAt,
        ];

        $format = ['%d', '%s', '%d', '%s', '%s', '%f', '%f', '%f', '%d', '%f', '%f', '%d', '%d', '%s', '%s'];

        if ($existing) {
            $wpdb->update($table, $row, ['id' => (int) $existing], $format, ['%d']);
            return (int) $existing;
        }

        $wpdb->insert($table, $row, $format);
        return $wpdb->insert_id ?: false;
    }

    /**
     * Bulk-upsert validators for a chain (no wallet_link_id required).
     * Used by the chain-level indexing cron. Matches on (chain_id, operator_address).
     *
     * @param array[] $validators Array of validator data arrays from fetch_all_validators().
     * @param int     $ttlSeconds TTL for expires_at.
     * @return int Number of rows written.
     */
    public static function bulkUpsert(array $validators, int $ttlSeconds = HOUR_IN_SECONDS): int
    {
        if (empty($validators)) {
            return 0;
        }

        global $wpdb;
        $table     = self::table();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $now       = current_time('mysql', true);
        $count     = 0;

        foreach ($validators as $data) {
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table}
                    (wallet_link_id, operator_address, chain_id, moniker, status,
                     commission_rate, total_stake, self_stake, delegator_count,
                     uptime_30d, governance_participation, jailed_count,
                     voting_power_rank, fetched_at, expires_at)
                 VALUES (NULL, %s, %d, %s, %s, %f, %f, %f, %d, %f, %f, %d, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    moniker                  = VALUES(moniker),
                    status                   = VALUES(status),
                    commission_rate          = VALUES(commission_rate),
                    total_stake              = VALUES(total_stake),
                    jailed_count             = VALUES(jailed_count),
                    voting_power_rank        = VALUES(voting_power_rank),
                    fetched_at               = VALUES(fetched_at),
                    expires_at               = VALUES(expires_at)",
                $data['operator_address'],
                (int) $data['chain_id'],
                $data['moniker'] ?? null,
                $data['status'] ?? 'unknown',
                $data['commission_rate'] ?? null,
                $data['total_stake'] ?? null,
                $data['self_stake'] ?? null,
                $data['delegator_count'] ?? null,
                $data['uptime_30d'] ?? null,
                $data['governance_participation'] ?? null,
                $data['jailed_count'] ?? 0,
                $data['voting_power_rank'] ?? null,
                $now,
                $expiresAt
            ));

            if ($result !== false) {
                $count++;
            }
        }

        return $count;
    }

    public static function getForWallet(int $walletLinkId): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT v.*, c.slug AS chain_slug, c.name AS chain_name, c.explorer_url, c.native_token
             FROM {$table} v
             JOIN {$chains} c ON c.id = v.chain_id
             WHERE v.wallet_link_id = %d
             ORDER BY v.voting_power_rank ASC, v.total_stake DESC",
            $walletLinkId
        ));
    }

    /**
     * @return array{items: array, total: int, pages: int}
     */
    public static function getForProject(int $postId, int $page = 1, int $perPage = 8, string $orderBy = 'total_stake'): array
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        $allowedOrder = ['total_stake', 'voting_power_rank', 'commission_rate', 'delegator_count', 'uptime_30d', 'chain_name'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'total_stake';
        }

        $orderDir = ($orderBy === 'voting_power_rank' || $orderBy === 'commission_rate') ? 'ASC' : 'DESC';
        $offset   = ($page - 1) * $perPage;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table} v
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             WHERE w.post_id = %d",
            $postId
        ));

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT v.*, c.slug AS chain_slug, c.name AS chain_name, c.explorer_url, c.native_token
             FROM {$table} v
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             JOIN {$chains} c ON c.id = v.chain_id
             WHERE w.post_id = %d
             ORDER BY v.{$orderBy} {$orderDir}
             LIMIT %d OFFSET %d",
            $postId, $perPage, $offset
        ));

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Get top validators globally (not per-project). Used by leaderboard block.
     *
     * @return array{items: array, total: int, pages: int}
     */
    public static function getTopValidators(int $page = 1, int $perPage = 20, string $orderBy = 'total_stake', ?int $chainId = null): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $allowedOrder = ['total_stake', 'voting_power_rank', 'commission_rate', 'delegator_count', 'uptime_30d'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'total_stake';
        }

        $orderDir = ($orderBy === 'voting_power_rank' || $orderBy === 'commission_rate') ? 'ASC' : 'DESC';
        $offset   = ($page - 1) * $perPage;

        $where  = '1=1';
        $params = [];

        if ($chainId) {
            $where   .= ' AND v.chain_id = %d';
            $params[] = $chainId;
        }

        $countSql = "SELECT COUNT(*) FROM {$table} v WHERE {$where}";
        $mainSql  = "SELECT v.*, c.slug AS chain_slug, c.name AS chain_name, c.explorer_url, c.native_token
                     FROM {$table} v
                     JOIN {$chains} c ON c.id = v.chain_id
                     WHERE {$where}
                     ORDER BY v.{$orderBy} {$orderDir}
                     LIMIT %d OFFSET %d";

        $countParams = $params;
        $mainParams  = array_merge($params, [$perPage, $offset]);

        $total = empty($countParams)
            ? (int) $wpdb->get_var($countSql)
            : (int) $wpdb->get_var($wpdb->prepare($countSql, ...$countParams));

        $items = empty($mainParams)
            ? $wpdb->get_results($mainSql)
            : $wpdb->get_results($wpdb->prepare($mainSql, ...$mainParams));

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
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
