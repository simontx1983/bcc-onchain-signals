<?php

namespace BCC\Onchain\Services;

use BCC\Onchain\Repositories\SignalRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the retry queue for failed trust-score bonus applications.
 *
 * Stores pending retries in wp_options (auto-loads false).
 * The retry cron processes the queue idempotently: recalculates
 * the bonus from stored signals so stale values are impossible.
 */
final class BonusRetryService
{
    private const OPTION_KEY    = 'bcc_onchain_pending_bonus';
    private const LOCK_KEY      = 'bcc_bonus_retry';
    private const MAX_ATTEMPTS  = 5;

    /**
     * Queue a failed bonus application for retry.
     *
     * Uses a MySQL advisory lock to prevent concurrent read-modify-write races.
     */
    public static function queue(int $pageId, float $bonus): void
    {
        if (!\BCC\Onchain\Repositories\LockRepository::acquire(self::LOCK_KEY, 5)) {
            return; // Could not acquire lock — will be picked up by retry cron.
        }

        try {
            $pending = get_option(self::OPTION_KEY, []);
            $pending[$pageId] = [
                'bonus'     => $bonus,
                'queued_at' => time(),
                'attempts'  => $pending[$pageId]['attempts'] ?? 0,
            ];
            update_option(self::OPTION_KEY, $pending, false);

            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-onchain-signals] bonus_queued_for_retry', [
                    'page_id'  => $pageId,
                    'bonus'    => $bonus,
                    'attempts' => $pending[$pageId]['attempts'],
                ]);
            }
        } finally {
            \BCC\Onchain\Repositories\LockRepository::release(self::LOCK_KEY);
        }
    }

    /**
     * Clear a page from the pending retry queue (called on success).
     *
     * Uses a MySQL advisory lock to prevent concurrent read-modify-write races.
     */
    public static function clear(int $pageId): void
    {
        if (!\BCC\Onchain\Repositories\LockRepository::acquire(self::LOCK_KEY, 5)) {
            return;
        }

        try {
            $pending = get_option(self::OPTION_KEY, []);
            if (isset($pending[$pageId])) {
                unset($pending[$pageId]);
                update_option(self::OPTION_KEY, $pending, false);
            }
        } finally {
            \BCC\Onchain\Repositories\LockRepository::release(self::LOCK_KEY);
        }
    }

    /**
     * Process all pending bonus retries.
     *
     * Idempotent: recalculates from stored signals + claims (source of truth).
     * Protected by advisory lock to prevent concurrent cron overlap.
     *
     * The lock is released before calling applyBonus() to avoid blocking
     * concurrent queue() calls from other processes. The pending list is
     * snapshotted under the lock, processed outside it, then results are
     * written back under a re-acquired lock.
     */
    public static function processAll(): void
    {
        // ── Step 1: snapshot pending entries under lock ──────────────────
        if (!\BCC\Onchain\Repositories\LockRepository::acquire(self::LOCK_KEY, 5)) {
            return;
        }

        $pending = get_option(self::OPTION_KEY, []);
        \BCC\Onchain\Repositories\LockRepository::release(self::LOCK_KEY);

        if (empty($pending)) {
            return;
        }

        if (!class_exists('\\BCC\\Core\\ServiceLocator')) {
            return;
        }

        if (!\BCC\Core\ServiceLocator::hasRealService(\BCC\Core\Contracts\ScoreContributorInterface::class)) {
            return; // Trust engine not active — retry next cycle without burning attempts
        }

        $contributor = \BCC\Core\ServiceLocator::resolveScoreContributor();
        $walletTable = \BCC\Onchain\Repositories\WalletRepository::table();

        // ── Step 2: process each entry WITHOUT holding the lock ──────────
        // This allows concurrent queue() calls to succeed.
        $succeeded = [];
        $failed    = [];
        $exhausted = [];

        foreach ($pending as $pageId => $entry) {
            if (($entry['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
                if (class_exists('BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-onchain-signals] bonus_retry_exhausted', [
                        'page_id'  => $pageId,
                        'attempts' => $entry['attempts'],
                    ]);
                }
                $exhausted[] = $pageId;
                continue;
            }

            // Recalculate from signals + claims (full bonus, not signals only).
            $signalBonus = array_sum(array_column(
                SignalRepository::get_for_page((int) $pageId),
                'score_contribution'
            ));

            $ownerId = \BCC\Core\PeepSo\PeepSo::get_page_owner((int) $pageId);
            $claimBonus = 0.0;
            if ($ownerId) {
                $claimBonus = \BCC\Onchain\Repositories\ClaimRepository::computePageClaimBonus(
                    $walletTable,
                    (int) $pageId,
                    $ownerId
                );
            }

            $totalBonus = min($signalBonus + $claimBonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);
            $applied    = $contributor->applyBonus((int) $pageId, 'onchain', $totalBonus);

            if ($applied) {
                $succeeded[] = $pageId;
            } else {
                $failed[$pageId] = ($entry['attempts'] ?? 0) + 1;
            }
        }

        // ── Step 3: write results back under lock ───────────────────────
        if (!\BCC\Onchain\Repositories\LockRepository::acquire(self::LOCK_KEY, 5)) {
            return; // Could not re-acquire — results will be picked up next cycle.
        }

        try {
            // Re-read option to merge with any entries added while we were processing.
            $current = get_option(self::OPTION_KEY, []);

            foreach ($succeeded as $pageId) {
                unset($current[$pageId]);
            }
            foreach ($exhausted as $pageId) {
                unset($current[$pageId]);
            }
            foreach ($failed as $pageId => $attempts) {
                if (isset($current[$pageId])) {
                    $current[$pageId]['attempts'] = $attempts;
                }
            }

            update_option(self::OPTION_KEY, $current, false);
        } finally {
            \BCC\Onchain\Repositories\LockRepository::release(self::LOCK_KEY);
        }
    }
}
