<?php

namespace BCC\Onchain\Services;

use BCC\Onchain\Repositories\ClaimRepository;
use BCC\Onchain\Repositories\CollectionRepository;
use BCC\Onchain\Repositories\SignalRepository;
use BCC\Onchain\Repositories\ValidatorRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies on-chain trust bonuses to page scores via the ScoreContributor contract.
 *
 * Two entry points:
 *   - applyBonus()      — raw bonus application (signals only)
 *   - applyClaimBonus() — combined signals + claim bonuses after a claim is verified
 */
final class BonusService
{
    /**
     * Claim bonus values by role.
     */
    private const CLAIM_BONUS_MAP = [
        'operator' => 5.0,
        'creator'  => 5.0,
        'holder'   => 1.0,
    ];

    /**
     * Apply an on-chain bonus to a page's trust score.
     *
     * Delegates to bcc-trust-engine via ScoreContributorInterface.
     * If unavailable, queues for retry.
     */
    public static function applyBonus(int $pageId, float $bonus): void
    {
        if (!class_exists('\\BCC\\Core\\ServiceLocator')
            || !\BCC\Core\ServiceLocator::hasRealService(\BCC\Core\Contracts\ScoreContributorInterface::class)
        ) {
            BonusRetryService::queue($pageId, $bonus);
            return;
        }

        $contributor = \BCC\Core\ServiceLocator::resolveScoreContributor();
        $applied     = $contributor->applyBonus($pageId, 'onchain', $bonus);

        if (!$applied) {
            BonusRetryService::queue($pageId, $bonus);
            return;
        }

        BonusRetryService::clear($pageId);
    }

    /**
     * Apply a trust bonus when an on-chain claim is verified.
     *
     * Recomputes the TOTAL bonus (signals + all verified claims) for the page,
     * then applies via applyBonus(). This avoids overwriting — applyBonus does
     * SET not ADD, so we always pass the full combined value.
     *
     * Hooked to: bcc_onchain_claim_verified
     */
    public static function applyClaimBonus(int $userId, string $entityType, int $entityId, string $role): void
    {
        $claimBonus = self::CLAIM_BONUS_MAP[$role] ?? 0.0;
        if ($claimBonus <= 0.0) {
            return;
        }

        global $wpdb;

        $entityTable = ($entityType === 'validator')
            ? ValidatorRepository::table()
            : CollectionRepository::table();

        $walletTable = \BCC\Core\DB\DB::table('wallet_links');

        // Resolve page_id: entity → wallet_link → post_id.
        $pageId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT w.post_id
             FROM {$entityTable} e
             JOIN {$walletTable} w ON w.id = e.wallet_link_id
             WHERE e.id = %d
             LIMIT 1",
            $entityId
        ));

        // Bulk-indexed entities have wallet_link_id = NULL.
        if (!$pageId) {
            $pageId = \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($userId);
        }

        if (!$pageId) {
            return;
        }

        // Recompute full bonus: signal scores + all claim bonuses.
        $signalBonus = array_sum(array_column(
            SignalRepository::get_for_page($pageId),
            'score_contribution'
        ));

        $claimsTable = ClaimRepository::table();
        $totalClaimBonus = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(bonus), 0) FROM (
                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$claimsTable} cl
                JOIN {$entityTable} e ON e.id = cl.entity_id AND cl.entity_type = %s
                JOIN {$walletTable} w ON w.id = e.wallet_link_id
                WHERE w.post_id = %d AND cl.status = 'verified'

                UNION ALL

                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$claimsTable} cl
                JOIN {$entityTable} e ON e.id = cl.entity_id AND cl.entity_type = %s
                WHERE e.wallet_link_id IS NULL
                  AND cl.status = 'verified'
                  AND cl.user_id = %d
            ) AS combined_bonuses",
            $entityType,
            $pageId,
            $entityType,
            $userId
        ));

        $totalBonus = min($signalBonus + $totalClaimBonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);

        self::applyBonus($pageId, $totalBonus);
    }
}
