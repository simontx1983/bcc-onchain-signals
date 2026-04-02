<?php

namespace BCC\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

final class CollectionRepository
{
    public static function table(): string
    {
        return DB::table('onchain_collections');
    }

    /**
     * Insert or update a collection row by wallet_link_id + chain_id + contract_address.
     *
     * @param array $data       Normalized collection data from a fetcher.
     * @param int   $walletLinkId  The wallet link this collection belongs to.
     * @param int   $ttlSeconds    Cache TTL before the row is considered expired.
     * @return int|false  Row ID on success, false on failure.
     */
    public static function upsert(array $data, int $walletLinkId, int $ttlSeconds = 4 * HOUR_IN_SECONDS)
    {
        global $wpdb;
        $table = self::table();

        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE wallet_link_id = %d AND chain_id = %d AND contract_address = %s
             LIMIT 1",
            $walletLinkId,
            (int) $data['chain_id'],
            $data['contract_address']
        ));

        $row = [
            'wallet_link_id'    => $walletLinkId,
            'contract_address'  => $data['contract_address'],
            'chain_id'          => (int) $data['chain_id'],
            'collection_name'   => $data['collection_name'] ?? null,
            'token_standard'    => $data['token_standard'] ?? null,
            'total_supply'      => $data['total_supply'] ?? null,
            'floor_price'       => $data['floor_price'] ?? null,
            'floor_currency'    => $data['floor_currency'] ?? null,
            'unique_holders'    => $data['unique_holders'] ?? null,
            'total_volume'      => $data['total_volume'] ?? null,
            'listed_percentage' => $data['listed_percentage'] ?? null,
            'royalty_percentage' => $data['royalty_percentage'] ?? null,
            'metadata_storage'  => $data['metadata_storage'] ?? null,
            'fetched_at'        => current_time('mysql', true),
            'expires_at'        => $expiresAt,
        ];

        $format = ['%d', '%s', '%d', '%s', '%s', '%d', '%f', '%s', '%d', '%f', '%f', '%f', '%s', '%s', '%s'];

        if ($existing) {
            $wpdb->update($table, $row, ['id' => (int) $existing], $format, ['%d']);
            return (int) $existing;
        }

        $wpdb->insert($table, $row, $format);
        return $wpdb->insert_id ?: false;
    }

    /**
     * Bulk-upsert collections for a chain (no wallet_link_id required).
     * Used by the chain-level indexing cron. Matches on (chain_id, contract_address).
     *
     * @param array[] $collections Normalized collection rows from fetch_top_collections().
     * @param int     $ttlSeconds  TTL for expires_at.
     * @return int Number of rows written.
     */
    public static function bulkUpsert(array $collections, int $ttlSeconds = 4 * HOUR_IN_SECONDS): int
    {
        if (empty($collections)) {
            return 0;
        }

        global $wpdb;
        $table     = self::table();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $now       = current_time('mysql', true);
        $count     = 0;

        foreach ($collections as $data) {
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table}
                    (wallet_link_id, contract_address, chain_id, collection_name, token_standard,
                     total_supply, floor_price, floor_currency, unique_holders, total_volume,
                     listed_percentage, royalty_percentage, metadata_storage, image_url,
                     fetched_at, expires_at)
                 VALUES (NULL, %s, %d, %s, %s, %d, %f, %s, %d, %f, %f, %f, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    collection_name    = VALUES(collection_name),
                    token_standard     = VALUES(token_standard),
                    total_supply       = VALUES(total_supply),
                    floor_price        = VALUES(floor_price),
                    unique_holders     = VALUES(unique_holders),
                    total_volume       = VALUES(total_volume),
                    listed_percentage  = VALUES(listed_percentage),
                    royalty_percentage  = VALUES(royalty_percentage),
                    image_url          = VALUES(image_url),
                    fetched_at         = VALUES(fetched_at),
                    expires_at         = VALUES(expires_at)",
                $data['contract_address'],
                (int) $data['chain_id'],
                $data['collection_name'] ?? null,
                $data['token_standard'] ?? null,
                $data['total_supply'] ?? null,
                $data['floor_price'] ?? null,
                $data['floor_currency'] ?? null,
                $data['unique_holders'] ?? null,
                $data['total_volume'] ?? null,
                $data['listed_percentage'] ?? null,
                $data['royalty_percentage'] ?? null,
                $data['metadata_storage'] ?? null,
                $data['image_url'] ?? null,
                $now,
                $expiresAt
            ));

            if ($result !== false) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get top collections globally (not per-project). Used by the discovery
     * collection leaderboard and "Claim Your Community" feed.
     *
     * @return array{items: array, total: int, pages: int}
     */
    public static function getTopCollections(int $page = 1, int $perPage = 20, string $orderBy = 'total_volume', ?int $chainId = null): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $allowedOrder = ['total_volume', 'floor_price', 'unique_holders', 'total_supply'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'total_volume';
        }

        $offset = ($page - 1) * $perPage;

        $where  = '1=1';
        $params = [];

        if ($chainId) {
            $where   .= ' AND c.chain_id = %d';
            $params[] = $chainId;
        }

        $countSql = "SELECT COUNT(*) FROM {$table} c WHERE {$where}";
        $mainSql  = "SELECT c.*, ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url, ch.native_token
                     FROM {$table} c
                     JOIN {$chains} ch ON ch.id = c.chain_id
                     WHERE {$where}
                     ORDER BY c.{$orderBy} DESC
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

    /**
     * @param bool $includeHidden If true, returns all collections regardless of show_on_profile.
     *                            Used by the page owner's dashboard. Public views pass false.
     * @return array{items: array, total: int, pages: int}
     */
    public static function getForProject(int $postId, int $page = 1, int $perPage = 8, string $orderBy = 'total_volume', bool $includeHidden = false): array
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        $allowedOrder = ['total_volume', 'floor_price', 'unique_holders', 'total_supply', 'collection_name'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'total_volume';
        }

        $offset = ($page - 1) * $perPage;

        $visibilityFilter = $includeHidden ? '' : ' AND c.show_on_profile = 1';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} c JOIN {$wallets} w ON w.id = c.wallet_link_id WHERE w.post_id = %d{$visibilityFilter}",
            $postId
        ));

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url, ch.native_token
             FROM {$table} c
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             JOIN {$chains} ch ON ch.id = c.chain_id
             WHERE w.post_id = %d{$visibilityFilter}
             ORDER BY c.{$orderBy} DESC
             LIMIT %d OFFSET %d",
            $postId, $perPage, $offset
        ));

        return ['items' => $items ?: [], 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    /**
     * Check whether a user holds NFTs from any of the given contract addresses.
     *
     * A "hold" means the user has a wallet_link whose address appears in the
     * onchain_collections table for that contract. This is an approximation:
     * the collection was fetched from that wallet, implying the wallet
     * interacted with (minted from) the contract.
     *
     * @param int      $userId           WordPress user ID.
     * @param string[] $contractAddresses Contract addresses to check.
     * @return array<string, bool> Keyed by lowercase contract address.
     */
    public static function getUserHoldings(int $userId, array $contractAddresses): array
    {
        if (empty($contractAddresses)) {
            return [];
        }

        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        $placeholders = implode(',', array_fill(0, count($contractAddresses), '%s'));
        $lowerAddrs   = array_map('strtolower', $contractAddresses);

        $args = array_merge([$userId], $lowerAddrs);

        $held = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT LOWER(c.contract_address)
             FROM {$table} c
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             WHERE w.user_id = %d AND LOWER(c.contract_address) IN ({$placeholders})",
            ...$args
        ));

        $result = [];
        foreach ($lowerAddrs as $addr) {
            $result[$addr] = in_array($addr, $held, true);
        }

        return $result;
    }

    /**
     * Toggle show_on_profile for a collection row owned by a user.
     *
     * @param int  $collectionId  Collection row ID.
     * @param int  $userId        Must own the wallet_link.
     * @param bool $show          Whether to show on profile.
     * @return bool True if updated.
     */
    public static function setShowOnProfile(int $collectionId, int $userId, bool $show): bool
    {
        global $wpdb;
        $table   = self::table();
        $wallets = WalletRepository::table();

        // Verify the user owns this collection row via wallet_link
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT c.id
             FROM {$table} c
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             WHERE c.id = %d AND w.user_id = %d
             LIMIT 1",
            $collectionId, $userId
        ));

        if (!$owned) {
            return false;
        }

        return (bool) $wpdb->update(
            $table,
            ['show_on_profile' => $show ? 1 : 0],
            ['id' => $collectionId],
            ['%d'],
            ['%d']
        );
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
