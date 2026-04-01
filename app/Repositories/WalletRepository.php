<?php

namespace BCC\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

final class WalletRepository
{
    public static function table(): string
    {
        return DB::table('wallet_links');
    }

    /**
     * @return int Inserted row ID, or 0 on failure.
     */
    public static function insert(array $data): int
    {
        global $wpdb;
        $table = self::table();

        $inserted = $wpdb->insert($table, [
            'user_id'        => (int) $data['user_id'],
            'post_id'        => (int) $data['post_id'],
            'wallet_address' => sanitize_text_field($data['wallet_address']),
            'chain_id'       => (int) $data['chain_id'],
            'wallet_type'    => sanitize_text_field($data['wallet_type'] ?? 'user'),
            'label'          => isset($data['label']) ? sanitize_text_field($data['label']) : null,
        ], ['%d', '%d', '%s', '%d', '%s', '%s']);

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Atomic insert-or-find using INSERT ... ON DUPLICATE KEY UPDATE.
     *
     * Relies on the UNIQUE KEY user_chain_wallet (user_id, chain_id, wallet_address).
     * If the row already exists, returns ['id' => existing_id, 'inserted' => false].
     * If newly inserted, returns ['id' => new_id, 'inserted' => true].
     * Returns ['id' => 0, 'inserted' => false] on hard failure.
     *
     * @return array{id: int, inserted: bool}
     */
    public static function insertOrFind(array $data): array
    {
        global $wpdb;
        $table = self::table();

        $userId  = (int) $data['user_id'];
        $postId  = (int) $data['post_id'];
        $address = sanitize_text_field($data['wallet_address']);
        $chainId = (int) $data['chain_id'];
        $type    = sanitize_text_field($data['wallet_type'] ?? 'user');
        $label   = isset($data['label']) ? sanitize_text_field($data['label']) : '';

        // id = LAST_INSERT_ID(id) on duplicate makes $wpdb->insert_id return
        // the existing row's ID, giving us a single round-trip atomic upsert.
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (user_id, post_id, wallet_address, chain_id, wallet_type, label)
             VALUES (%d, %d, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            $userId, $postId, $address, $chainId, $type, $label
        ));

        if ($result === false) {
            return ['id' => 0, 'inserted' => false];
        }

        $id = (int) $wpdb->insert_id;

        // $wpdb->rows_affected: 1 = inserted, 2 = duplicate key triggered update
        return [
            'id'       => $id,
            'inserted' => ((int) $wpdb->rows_affected === 1),
        ];
    }

    public static function verify(int $walletLinkId): bool
    {
        global $wpdb;
        $table = self::table();

        return (bool) $wpdb->update(
            $table,
            ['verified_at' => current_time('mysql', true)],
            ['id' => $walletLinkId],
            ['%s'],
            ['%d']
        );
    }

    public static function delete(int $walletLinkId, int $userId): bool
    {
        global $wpdb;
        $table = self::table();

        return (bool) $wpdb->delete(
            $table,
            ['id' => $walletLinkId, 'user_id' => $userId],
            ['%d', '%d']
        );
    }

    public static function setPrimary(int $walletLinkId, int $userId): bool
    {
        global $wpdb;
        $table = self::table();

        $chainId = $wpdb->get_var($wpdb->prepare(
            "SELECT chain_id FROM {$table} WHERE id = %d AND user_id = %d",
            $walletLinkId, $userId
        ));

        if (!$chainId) {
            return false;
        }

        // Single atomic UPDATE: set is_primary based on whether the row ID
        // matches the target. This prevents the dual-primary state that could
        // occur if two separate UPDATEs (clear all → set one) are interrupted.
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET is_primary = CASE WHEN id = %d THEN 1 ELSE 0 END
             WHERE user_id = %d AND chain_id = %d",
            $walletLinkId,
            $userId,
            $chainId
        ));

        return $result !== false;
    }

    public static function getForUser(int $userId, ?string $walletType = null, bool $verifiedOnly = false): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $where = ['w.user_id = %d'];
        $args  = [$userId];

        if ($walletType) {
            $where[] = 'w.wallet_type = %s';
            $args[]  = $walletType;
        }

        if ($verifiedOnly) {
            $where[] = 'w.verified_at IS NOT NULL';
        }

        $whereSql = implode(' AND ', $where);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT w.*, c.slug AS chain_slug, c.name AS chain_name, c.chain_type, c.explorer_url
             FROM {$table} w
             JOIN {$chains} c ON c.id = w.chain_id
             WHERE {$whereSql}
             ORDER BY w.is_primary DESC, w.created_at ASC",
            ...$args
        ));
    }

    public static function getForProject(int $postId, ?string $walletType = null): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $where = ['w.post_id = %d'];
        $args  = [$postId];

        if ($walletType) {
            $where[] = 'w.wallet_type = %s';
            $args[]  = $walletType;
        }

        $whereSql = implode(' AND ', $where);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT w.*, c.slug AS chain_slug, c.name AS chain_name, c.chain_type, c.explorer_url
             FROM {$table} w
             JOIN {$chains} c ON c.id = w.chain_id
             WHERE {$whereSql}
             ORDER BY w.is_primary DESC, w.wallet_type ASC, w.created_at ASC",
            ...$args
        ));
    }

    public static function getById(int $walletLinkId): ?object
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT w.*, c.slug AS chain_slug, c.name AS chain_name, c.chain_type, c.explorer_url
             FROM {$table} w
             JOIN {$chains} c ON c.id = w.chain_id
             WHERE w.id = %d",
            $walletLinkId
        ));
    }

    public static function exists(int $userId, int $chainId, string $walletAddress): bool
    {
        global $wpdb;
        $table = self::table();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$table} WHERE user_id = %d AND chain_id = %d AND wallet_address = %s",
            $userId, $chainId, $walletAddress
        ));
    }
}
