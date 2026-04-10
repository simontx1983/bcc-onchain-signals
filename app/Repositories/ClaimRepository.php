<?php
/**
 * Claim Repository
 *
 * CRUD for on-chain entity claims (validator operator, NFT creator/holder).
 *
 * @package BCC\Onchain\Repositories
 */

namespace BCC\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class ClaimRepository {

    /** @var string Explicit column list — must match schema-claims.php. */
    private const COLUMNS = 'id, user_id, entity_type, entity_id, wallet_address,
                 chain_id, claim_role, status, verified_at, created_at';

    public static function table(): string {
        return \BCC\Core\DB\DB::table('onchain_claims');
    }

    /**
     * Insert or update a claim. UNIQUE on (user_id, entity_type, entity_id).
     *
     * @return int|false Claim ID or false on failure.
     */
    public static function upsert(int $userId, string $entityType, int $entityId, string $walletAddress, int $chainId, string $claimRole, string $status = 'verified'): int|false {
        global $wpdb;
        $table = self::table();

        $verifiedAt = ($status === 'verified') ? current_time('mysql', true) : null;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (user_id, entity_type, entity_id, wallet_address, chain_id, claim_role, status, verified_at, created_at)
             VALUES (%d, %s, %d, %s, %d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                wallet_address = VALUES(wallet_address),
                chain_id       = VALUES(chain_id),
                claim_role     = VALUES(claim_role),
                status         = VALUES(status),
                verified_at    = VALUES(verified_at)",
            $userId,
            $entityType,
            $entityId,
            $walletAddress,
            $chainId,
            $claimRole,
            $status,
            $verifiedAt,
            current_time('mysql', true)
        ));

        if ($wpdb->last_error) {
            return false;
        }

        return (int) $wpdb->insert_id ?: (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND entity_type = %s AND entity_id = %d",
            $userId, $entityType, $entityId
        ));
    }

    /**
     * Get the verified primary claim (exclusive role) for an entity.
     * Returns the single operator/creator claim if one exists, or null.
     * Uses idx_entity index: (entity_type, entity_id).
     */
    public static function getPrimaryClaim(string $entityType, int $entityId, string $role): ?object {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT cl.id, cl.user_id, cl.entity_type, cl.entity_id, cl.wallet_address,
                    cl.chain_id, cl.claim_role, cl.status, cl.verified_at, cl.created_at,
                    u.display_name AS claimer_name
             FROM {$table} cl
             INNER JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.entity_type = %s AND cl.entity_id = %d
               AND cl.claim_role = %s AND cl.status = 'verified'
             LIMIT 1",
            $entityType,
            $entityId,
            $role
        ));
    }

    /**
     * Get all verified claims for an entity.
     *
     * Wallet addresses are NOT included by default to prevent data leaks.
     * Pass $includeWalletAddress = true only when the caller needs it
     * (e.g. admin views).
     *
     * @return array Array of claim objects with user display_name.
     */
    public static function getForEntity(string $entityType, int $entityId, bool $includeWalletAddress = false): array {
        global $wpdb;
        $table = self::table();

        $walletCol = $includeWalletAddress ? ', cl.wallet_address' : '';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT cl.id, cl.user_id, cl.entity_type, cl.entity_id{$walletCol},
                    cl.chain_id, cl.claim_role, cl.status, cl.verified_at, cl.created_at,
                    u.display_name AS claimer_name
             FROM {$table} cl
             INNER JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.entity_type = %s AND cl.entity_id = %d AND cl.status = 'verified'
             ORDER BY cl.verified_at ASC",
            $entityType,
            $entityId
        ));
    }

    /**
     * Get a user's claim on a specific entity (if any).
     */
    public static function getUserClaim(int $userId, string $entityType, int $entityId): ?object {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table}
             WHERE user_id = %d AND entity_type = %s AND entity_id = %d",
            $userId, $entityType, $entityId
        ));
    }

    /**
     * Batch-check which page IDs have any verified primary claim
     * (operator or creator). Returns page_id => claimer_name map.
     *
     * Joins through entity tables → wallet_links to resolve page ownership.
     * Single query, no N+1.
     *
     * @param int[] $pageIds
     * @return array<int, string> page_id => claimer display_name
     */
    public static function getPrimaryClaimsByPageIds(array $pageIds): array {
        if (empty($pageIds)) {
            return [];
        }

        global $wpdb;
        $table       = self::table();
        $validators  = \BCC\Core\DB\DB::table('onchain_validators');
        $collections = \BCC\Core\DB\DB::table('onchain_collections');
        $wallets     = \BCC\Core\DB\DB::table('wallet_links');

        $ph = implode(',', array_fill(0, count($pageIds), '%d'));

        // Union validators + collections claims, joined to wallet_links for page_id.
        $sql = $wpdb->prepare(
            "SELECT w.post_id AS page_id, u.display_name AS claimer_name, cl.claim_role
             FROM {$table} cl
             JOIN {$validators} v ON v.id = cl.entity_id AND cl.entity_type = 'validator'
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.status = 'verified' AND cl.claim_role IN ('operator','creator')
               AND w.post_id IN ({$ph})

             UNION ALL

             SELECT w.post_id AS page_id, u.display_name AS claimer_name, cl.claim_role
             FROM {$table} cl
             JOIN {$collections} c ON c.id = cl.entity_id AND cl.entity_type = 'collection'
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.status = 'verified' AND cl.claim_role IN ('operator','creator')
               AND w.post_id IN ({$ph})",
            ...array_merge($pageIds, $pageIds)
        );

        $rows = $wpdb->get_results($sql);

        $map = [];
        foreach ($rows as $row) {
            // First primary claim wins per page (operator > creator by query order).
            if (!isset($map[(int) $row->page_id])) {
                $map[(int) $row->page_id] = $row->claimer_name;
            }
        }

        return $map;
    }

    /**
     * Batch-load all verified claims for multiple entities of the same type.
     * Single query replacing N per-entity lookups.
     *
     * @param string $entityType 'validator' or 'collection'
     * @param int[]  $entityIds
     * @return array<int, object[]> entity_id => array of claim objects
     */
    public static function getForEntityBatch(string $entityType, array $entityIds): array {
        if (empty($entityIds)) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $ph    = implode(',', array_fill(0, count($entityIds), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cl.id, cl.user_id, cl.entity_type, cl.entity_id, cl.wallet_address,
                    cl.chain_id, cl.claim_role, cl.status, cl.verified_at, cl.created_at,
                    u.display_name AS claimer_name
             FROM {$table} cl
             INNER JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.entity_type = %s AND cl.entity_id IN ({$ph}) AND cl.status = 'verified'
             ORDER BY cl.verified_at ASC",
            $entityType,
            ...$entityIds
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->entity_id][] = $row;
        }

        return $map;
    }

    /**
     * Atomically create an exclusive claim (operator/creator).
     *
     * Uses a MySQL advisory lock scoped to the entity+role to serialize
     * concurrent claims. The lock prevents TOCTOU races between the
     * getPrimaryClaim check and the upsert.
     *
     * MySQL does not support partial unique indexes, so exclusivity for
     * operator/creator roles is enforced at the application level with
     * this advisory lock (not via a DB UNIQUE KEY, which would also
     * block non-exclusive holder claims).
     *
     * @return array{success: bool, claim_id?: int, error?: string, message?: string}
     */
    public static function createExclusiveClaim(
        int $userId,
        string $entityType,
        int $entityId,
        string $walletAddress,
        int $chainId,
        string $role
    ): array {
        // Advisory lock scoped to this specific entity+role.
        $lockKey = "bcc_claim:{$entityType}:{$entityId}:{$role}";
        if (!LockRepository::acquire($lockKey, 5)) {
            return ['success' => false, 'message' => 'Could not acquire claim lock. Please try again.'];
        }

        try {
            // Under lock: check if anyone already holds this exclusive role.
            $existingPrimary = self::getPrimaryClaim($entityType, $entityId, $role);

            if ($existingPrimary) {
                if ((int) $existingPrimary->user_id === $userId) {
                    // Idempotent re-claim by the same user.
                    return ['success' => true, 'claim_id' => (int) $existingPrimary->id];
                }

                $roleLabel = ucfirst($role);
                return [
                    'success' => false,
                    'error'   => 'already_claimed',
                    'message' => "This project already has a verified {$roleLabel}.",
                ];
            }

            // No one holds this role yet — safe to upsert under the lock.
            $claimId = self::upsert($userId, $entityType, $entityId, $walletAddress, $chainId, $role, 'verified');

            if (!$claimId) {
                return ['success' => false, 'message' => 'Failed to save claim.'];
            }

            return ['success' => true, 'claim_id' => $claimId];
        } finally {
            LockRepository::release($lockKey);
        }
    }

    /**
     * Delete all claims linked to a specific wallet address for a user.
     *
     * Called when a wallet is disconnected so that the trust bonus is revoked.
     *
     * @return int Number of rows deleted.
     */
    public static function deleteByUserAndWallet(int $userId, string $walletAddress): int {
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE user_id = %d AND wallet_address = %s",
            $userId,
            $walletAddress
        ));
    }

    /**
     * Compute the total claim bonus for a page across ALL entity types.
     *
     * Aggregates validator AND collection claims. Wallet-linked entities are
     * matched via wallet_link → post_id. Bulk-indexed entities (wallet_link_id
     * IS NULL) are matched via the claiming user's ID.
     */
    public static function computePageClaimBonus(
        string $walletTable,
        int $pageId,
        int $userId
    ): float {
        global $wpdb;
        $table       = self::table();
        $validators  = \BCC\Core\DB\DB::table('onchain_validators');
        $collections = \BCC\Core\DB\DB::table('onchain_collections');

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(bonus), 0) FROM (
                /* Validator claims linked via wallet */
                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$table} cl
                JOIN {$validators} e ON e.id = cl.entity_id AND cl.entity_type = 'validator'
                JOIN {$walletTable} w ON w.id = e.wallet_link_id
                WHERE w.post_id = %d AND cl.status = 'verified'

                UNION ALL

                /* Validator claims on bulk-indexed entities (no wallet_link) */
                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$table} cl
                JOIN {$validators} e ON e.id = cl.entity_id AND cl.entity_type = 'validator'
                WHERE e.wallet_link_id IS NULL
                  AND cl.status = 'verified'
                  AND cl.user_id = %d

                UNION ALL

                /* Collection claims linked via wallet */
                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$table} cl
                JOIN {$collections} e ON e.id = cl.entity_id AND cl.entity_type = 'collection'
                JOIN {$walletTable} w ON w.id = e.wallet_link_id
                WHERE w.post_id = %d AND cl.status = 'verified'

                UNION ALL

                /* Collection claims on bulk-indexed entities (no wallet_link) */
                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$table} cl
                JOIN {$collections} e ON e.id = cl.entity_id AND cl.entity_type = 'collection'
                WHERE e.wallet_link_id IS NULL
                  AND cl.status = 'verified'
                  AND cl.user_id = %d
            ) AS combined_bonuses",
            $pageId,
            $userId,
            $pageId,
            $userId
        ));
    }

}
