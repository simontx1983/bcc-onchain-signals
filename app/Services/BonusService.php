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

        $entityTable = ($entityType === 'validator')
            ? ValidatorRepository::table()
            : CollectionRepository::table();

        $walletTable = \BCC\Onchain\Repositories\WalletRepository::table();

        // Resolve page_id: entity → wallet_link → post_id.
        $pageId = \BCC\Onchain\Repositories\WalletRepository::getPostIdForEntity($entityTable, $entityId);

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

        $totalClaimBonus = ClaimRepository::computePageClaimBonus(
            $entityType,
            $entityTable,
            $walletTable,
            $pageId,
            $userId
        );

        $totalBonus = min($signalBonus + $totalClaimBonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);

        self::applyBonus($pageId, $totalBonus);
    }
}
