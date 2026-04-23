<?php

namespace BCC\Onchain\Services;

use BCC\Onchain\Repositories\ClaimRepository;
use BCC\Onchain\Repositories\LockRepository;
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
     * Handle wallet disconnect: revoke claims for the wallet and recalculate bonuses.
     *
     * Extracted from the boot file closure so business logic lives in a service.
     *
     * Atomicity: the claim delete and signal delete MUST commit together.
     * Under DB latency spikes (200-500ms), a PHP timeout between the two
     * deletes would leave claims revoked but signals intact — yielding an
     * inflated onchain_bonus that only self-heals on the next signal refresh
     * (up to 24h later). Both deletes run inside a single transaction;
     * recomputeAndApply runs AFTER commit because it takes an advisory lock
     * and calls the trust-engine, neither of which belong inside a DB txn.
     */
    public static function handleWalletDisconnect(int $userId, string $chainSlug, string $walletAddress): void
    {
        global $wpdb;

        // Resolve chain_id so deletion is scoped to the specific chain.
        // Prevents collateral deletion of claims on other chains sharing
        // the same address format (e.g., Ethereum + Polygon + Arbitrum).
        $chainId = \BCC\Onchain\Repositories\ChainRepository::resolveId($chainSlug);

        $wpdb->query('START TRANSACTION');
        try {
            // Remove claims tied to this wallet address ON THIS CHAIN ONLY.
            $deleted = \BCC\Onchain\Repositories\ClaimRepository::deleteByUserAndWallet($userId, $walletAddress, $chainId);

            // Delete signal rows for the disconnected wallet so stale scores
            // don't persist. Without this, the daily cron won't refresh a wallet
            // that's no longer linked, and its score_contribution lingers forever.
            SignalRepository::deleteByWallet($walletAddress, $chainSlug);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-onchain] handleWalletDisconnect rolled back', [
                    'user_id' => $userId,
                    'chain'   => $chainSlug,
                    'error'   => $e->getMessage(),
                ]);
            }
            throw $e;
        }

        // Recalculate the on-chain bonus with the claims AND signals removed.
        // Must include BOTH signal scores AND remaining claim bonuses
        // (from other wallets the user still has connected).
        // Idempotent: if anything below fails, the next refresh recomputes.
        $pageId = \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($userId);
        if ($pageId) {
            self::recomputeAndApply($pageId, $userId);
        }

        if ($deleted && class_exists('BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::info('[bcc-onchain] claims revoked on wallet disconnect', [
                'user_id' => $userId, 'wallet' => $walletAddress, 'claims_deleted' => $deleted,
            ]);
        }
    }

    /**
     * Acquire a MySQL named lock to serialize bonus recomputation per page.
     *
     * Returns true if the lock was acquired, false on timeout. The caller
     * MUST call releaseBonusLock() when done (use try/finally).
     */
    private static function acquireBonusLock(int $pageId, int $timeoutSeconds = 5): bool
    {
        return LockRepository::acquire('bcc_onchain_bonus_' . $pageId, $timeoutSeconds);
    }

    private static function releaseBonusLock(int $pageId): void
    {
        LockRepository::release('bcc_onchain_bonus_' . $pageId);
    }

    /**
     * Recompute and apply the full on-chain bonus for a page.
     *
     * Serialized via MySQL named lock to prevent concurrent recomputations
     * from overwriting each other with stale snapshots.
     */
    public static function recomputeAndApply(int $pageId, int $userId): void
    {
        $locked = self::acquireBonusLock($pageId);
        if (!$locked) {
            BonusRetryService::queue($pageId, 0.0);
            return;
        }

        try {
            $signalBonus = array_sum(array_column(
                SignalRepository::get_for_page($pageId),
                'score_contribution'
            ));

            $walletTable = \BCC\Onchain\Repositories\WalletRepository::table();
            $claimBonus  = ClaimRepository::computePageClaimBonus(
                $walletTable,
                $pageId,
                $userId
            );

            $totalBonus = min($signalBonus + $claimBonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);
            self::applyBonus($pageId, $totalBonus);
        } finally {
            self::releaseBonusLock($pageId);
        }
    }

    /**
     * Apply a trust bonus when an on-chain claim is verified.
     *
     * Recomputes the TOTAL bonus (signals + all verified claims) for the page,
     * then applies via applyBonus(). This avoids overwriting — applyBonus does
     * SET not ADD, so we always pass the full combined value.
     *
     * Serialized per-page via MySQL GET_LOCK to prevent concurrent claim
     * verifications from reading stale snapshots and overwriting each other.
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

        // Resolve page_id: entity → wallet_link → post_id.
        $pageId = \BCC\Onchain\Repositories\WalletRepository::getPostIdForEntity($entityTable, $entityId);

        // Bulk-indexed entities have wallet_link_id = NULL.
        if (!$pageId) {
            $pageId = \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($userId);
        }

        if (!$pageId) {
            return;
        }

        self::recomputeAndApply($pageId, $userId);
    }
}
