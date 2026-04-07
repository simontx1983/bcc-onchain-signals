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
            'wallet_link_id'   => $walletLinkId,
            'operator_address' => $data['operator_address'],
            'chain_id'         => (int) $data['chain_id'],
            'moniker'          => isset($data['moniker']) ? sanitize_text_field($data['moniker']) : null,
            'status'           => sanitize_text_field($data['status'] ?? 'unknown'),
            'commission_rate'  => $data['commission_rate'] ?? null,
            'total_stake'      => $data['total_stake'] ?? null,
            'self_stake'       => $data['self_stake'] ?? null,
            'delegator_count'  => $data['delegator_count'] ?? null,
            'uptime_30d'       => $data['uptime_30d'] ?? null,
            'jailed_count'     => $data['jailed_count'] ?? 0,
            'voting_power_rank'=> $data['voting_power_rank'] ?? null,
            'fetched_at'       => current_time('mysql', true),
            'expires_at'       => $expiresAt,
        ];

        $format = ['%d', '%s', '%d', '%s', '%s', '%f', '%f', '%f', '%d', '%f', '%d', '%d', '%s', '%s'];

        if ($existing) {
            $wpdb->update($table, $row, ['id' => (int) $existing], $format, ['%d']);
            return (int) $existing;
        }

        $wpdb->insert($table, $row, $format);
        return $wpdb->insert_id ?: false;
    }

    /**
     * Enrich a validator row with expensive per-validator data.
     * Matches by (chain_id, operator_address) — works for both
     * wallet-linked and bulk-indexed (NULL wallet_link_id) rows.
     *
     * Only updates columns that have non-null values in $data,
     * preserving existing data for fields the fetcher didn't return.
     */
    public static function enrichByOperator(array $data, int $ttlSeconds = HOUR_IN_SECONDS): bool
    {
        global $wpdb;
        $table = self::table();

        $sets   = [];
        $params = [];

        $enrichable = [
            'self_stake'       => '%f',
            'delegator_count'  => '%d',
            'uptime_30d'       => '%f',
            'moniker'          => '%s',
            'status'           => '%s',
            'commission_rate'  => '%f',
            'total_stake'      => '%f',
            'jailed_count'     => '%d',
            'voting_power_rank'=> '%d',
        ];

        foreach ($enrichable as $col => $fmt) {
            if (isset($data[$col]) && $data[$col] !== null) {
                $sets[]   = "{$col} = {$fmt}";
                $params[] = $data[$col];
            }
        }

        if (empty($sets)) {
            return false;
        }

        // Always update timestamps.
        $sets[]   = 'fetched_at = %s';
        $params[] = current_time('mysql', true);
        $sets[]   = 'expires_at = %s';
        $params[] = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        // WHERE clause.
        $params[] = (int) $data['chain_id'];
        $params[] = $data['operator_address'];

        $sql = "UPDATE {$table} SET " . implode(', ', $sets)
             . " WHERE chain_id = %d AND operator_address = %s";

        $result = $wpdb->query($wpdb->prepare($sql, ...$params));
        return $result !== false;
    }

    /**
     * Bulk-upsert validators for a chain (no wallet_link_id required).
     * Used by the chain-level indexing cron. Matches on (chain_id, operator_address).
     *
     * @param array[] $validators Array of validator data arrays from fetch_all_validators().
     * @param int     $ttlSeconds TTL for expires_at.
     * @return int Number of rows written.
     */
    /**
     * Bulk-upsert validators for a chain. Lean write strategy:
     *
     *   1. SELECT existing rows for this chain (1 query)
     *   2. Compare each incoming validator against the existing row
     *   3. NEW rows → INSERT individually (with staggered next_enrichment_at)
     *   4. CHANGED rows → UPDATE individually (data columns + reset retry)
     *   5. UNCHANGED rows → skip entirely (zero writes)
     *   6. Time-gated fetched_at — batch UPDATE for rows not seen in 6h+ (1 query)
     *
     * Write budget at 500 validators, ~20 changed, ~80 stale fetched_at:
     *   20 individual UPDATEs + 1 batch UPDATE = 21 queries (down from 500).
     *
     * @param array[] $validators Array of validator data arrays from fetch_all_validators().
     * @param int     $ttlSeconds TTL for expires_at.
     * @return array{total: int, new: int, updated: int, unchanged: int, refreshed: int}
     */
    public static function bulkUpsert(array $validators, int $ttlSeconds = HOUR_IN_SECONDS): array
    {
        $stats = ['total' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'refreshed' => 0];

        if (empty($validators)) {
            return $stats;
        }

        // How often to touch fetched_at on unchanged rows (observability).
        // Every 6 hours keeps the "last seen" timestamp useful for dead
        // validator detection without writing on every 4-hour index cycle.
        $fetchedAtStaleThreshold = 6 * HOUR_IN_SECONDS;

        global $wpdb;
        $table     = self::table();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $now       = current_time('mysql', true);

        // ── Step 1: fetch existing rows for this chain in one query ──────
        $chainId = (int) $validators[0]['chain_id'];
        $existingRows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, operator_address, moniker, status, commission_rate,
                    total_stake, jailed_count, voting_power_rank,
                    enrichment_attempts, fetched_at
             FROM {$table}
             WHERE chain_id = %d",
            $chainId
        ));

        $existing = [];
        foreach ($existingRows as $row) {
            $existing[$row->operator_address] = $row;
        }

        $stats['total'] = count($validators);

        // Collect IDs of unchanged rows whose fetched_at is stale (>6h).
        // These get a single batch UPDATE at the end instead of N individual writes.
        $staleFetchedIds = [];

        foreach ($validators as $data) {
            $addr = $data['operator_address'];
            $prev = $existing[$addr] ?? null;

            if ($prev === null) {
                // ── NEW ─────────────────────────────────────────────────
                $jitterSec        = crc32($addr) & 0x3FFF;
                $nextEnrichmentAt = gmdate('Y-m-d H:i:s', time() + $jitterSec);

                // Enrichment-only columns (self_stake, delegator_count, uptime_30d)
                // are omitted — they default to NULL in the schema and are populated
                // by the EnrichmentScheduler. Using %f with null would store 0.00
                // instead of NULL, which breaks the "needs enrichment" detection.
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table}
                        (wallet_link_id, operator_address, chain_id, moniker, status,
                         commission_rate, total_stake, jailed_count,
                         voting_power_rank, fetched_at, expires_at, next_enrichment_at)
                     VALUES (NULL, %s, %d, %s, %s, %f, %f, %d, %d, %s, %s, %s)",
                    $addr,
                    $chainId,
                    $data['moniker'] ?? null,
                    $data['status'] ?? 'unknown',
                    $data['commission_rate'] ?? null,
                    $data['total_stake'] ?? null,
                    $data['jailed_count'] ?? 0,
                    $data['voting_power_rank'] ?? null,
                    $now,
                    $expiresAt,
                    $nextEnrichmentAt
                ));
                $stats['new']++;
                continue;
            }

            // ── EXISTING: check if anything the indexer owns has changed ─
            $changed = ($data['moniker'] ?? null)           !== ($prev->moniker ?? null)
                || ($data['status'] ?? 'unknown')           !== ($prev->status ?? 'unknown')
                || round((float) ($data['commission_rate'] ?? 0), 2) !== round((float) ($prev->commission_rate ?? 0), 2)
                || round((float) ($data['total_stake'] ?? 0), 6)     !== round((float) ($prev->total_stake ?? 0), 6)
                || (int) ($data['jailed_count'] ?? 0)       !== (int) ($prev->jailed_count ?? 0)
                || (int) ($data['voting_power_rank'] ?? 0)  !== (int) ($prev->voting_power_rank ?? 0)
                || (int) ($prev->enrichment_attempts ?? 0)  > 0;  // reset stuck validators

            if (!$changed) {
                // UNCHANGED — no per-row write. If fetched_at is stale (>6h),
                // collect the ID for a batch timestamp refresh at the end.
                $fetchedAge = $prev->fetched_at
                    ? (time() - strtotime($prev->fetched_at))
                    : PHP_INT_MAX;

                if ($fetchedAge >= $fetchedAtStaleThreshold) {
                    $staleFetchedIds[] = (int) $prev->id;
                }

                $stats['unchanged']++;
                continue;
            }

            // ── CHANGED — per-row UPDATE (data columns) ─────────────────
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET moniker             = %s,
                     status              = %s,
                     commission_rate     = %f,
                     total_stake         = %f,
                     jailed_count        = %d,
                     voting_power_rank   = %d,
                     fetched_at          = %s,
                     expires_at          = %s,
                     enrichment_attempts = 0,
                     retry_after         = NULL
                 WHERE id = %d",
                $data['moniker'] ?? null,
                $data['status'] ?? 'unknown',
                $data['commission_rate'] ?? null,
                $data['total_stake'] ?? null,
                $data['jailed_count'] ?? 0,
                $data['voting_power_rank'] ?? null,
                $now,
                $expiresAt,
                (int) $prev->id
            ));
            $stats['updated']++;
        }

        // ── Step 6: batch timestamp refresh for stale unchanged rows ────
        // Single UPDATE per chunk instead of N individual writes.
        if (!empty($staleFetchedIds)) {
            $chunks = array_chunk($staleFetchedIds, 200);
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET fetched_at = %s WHERE id IN ({$placeholders})",
                    $now,
                    ...$chunk
                ));
            }
            $stats['refreshed'] = count($staleFetchedIds);
        }

        return $stats;
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
    /**
     * @param int         $page
     * @param int         $perPage
     * @param string      $orderBy
     * @param int|null    $chainId    Filter by chain.
     * @param string|null $timeWindow Filter by fetched_at window: '1h','6h','12h','1d','7d','30d'. Null = all.
     * @return array{items: array, total: int, pages: int}
     */
    public static function getTopValidators(int $page = 1, int $perPage = 20, string $orderBy = 'total_stake', ?int $chainId = null, ?string $timeWindow = null, ?string $direction = null): array
    {
        global $wpdb;
        $table  = self::table();
        $chains = ChainRepository::table();

        $allowedOrder = ['total_stake', 'self_stake', 'voting_power_rank', 'commission_rate', 'delegator_count', 'uptime_30d'];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'total_stake';
        }

        // Default direction: ASC for rank/commission (lower is better), DESC for everything else.
        $defaultDir = ($orderBy === 'voting_power_rank' || $orderBy === 'commission_rate') ? 'ASC' : 'DESC';
        $orderDir   = ($direction === 'asc' || $direction === 'desc') ? strtoupper($direction) : $defaultDir;
        $offset   = ($page - 1) * $perPage;

        $where  = '1=1';
        $params = [];

        if ($chainId) {
            $where   .= ' AND v.chain_id = %d';
            $params[] = $chainId;
        }

        // Time window filter — show validators fetched within this period.
        $windowMap = [
            '1h'  => 1,      '6h'  => 6,       '12h' => 12,
            '1d'  => 24,     '7d'  => 24 * 7,   '30d' => 24 * 30,
        ];
        if ($timeWindow && isset($windowMap[$timeWindow])) {
            $where   .= ' AND v.fetched_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)';
            $params[] = $windowMap[$timeWindow];
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

}
