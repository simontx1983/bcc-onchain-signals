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
    private const MAX_ATTEMPTS       = 20;
    private const QUARANTINE_KEY     = 'bcc_onchain_quarantined_bonus';

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
            /** @var array<int, array{bonus: float, queued_at: int, attempts: int}> $pending */
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
    private const PROCESS_LOCK_KEY = 'bcc_bonus_retry_process';

    public static function processAll(): void
    {
        // Exclusive processing lock — prevents concurrent processAll() calls
        // from applying the same bonuses twice. Separate from LOCK_KEY which
        // protects the queue option read/write.
        if (!\BCC\Onchain\Repositories\LockRepository::acquire(self::PROCESS_LOCK_KEY, 0)) {
            return; // Another processAll() is already running.
        }

        try {

        // ── Step 1: snapshot pending entries under queue lock ────────────
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
                    \BCC\Core\Log\Logger::error('[bcc-onchain-signals] bonus_retry_exhausted_quarantined', [
                        'page_id'  => $pageId,
                        'attempts' => $entry['attempts'],
                    ]);
                }
                // Quarantine instead of silently dropping. Admin can inspect
                // and manually retry via wp_options → bcc_onchain_quarantined_bonus.
                self::quarantine($pageId, $entry);
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
        $lockAcquired = \BCC\Onchain\Repositories\LockRepository::acquire(self::LOCK_KEY, 5);
        if (!$lockAcquired) {
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

        } finally {
            \BCC\Onchain\Repositories\LockRepository::release(self::PROCESS_LOCK_KEY);
        }
    }

    /**
     * Move a permanently failed entry to a quarantine option instead of
     * silently dropping it. Admins can inspect and manually retry via
     * wp_options → bcc_onchain_quarantined_bonus.
     *
     * @param int                                          $pageId
     * @param array{bonus: float, queued_at: int, attempts: int} $entry
     */
    private static function quarantine(int $pageId, array $entry): void
    {
        /** @var array<int, array{bonus: float, queued_at: int, attempts: int, quarantined_at: int}> $quarantined */
        $quarantined = get_option(self::QUARANTINE_KEY, []);
        $quarantined[$pageId] = [
            'bonus'          => $entry['bonus'] ?? 0.0,
            'queued_at'      => $entry['queued_at'] ?? 0,
            'attempts'       => $entry['attempts'] ?? 0,
            'quarantined_at' => time(),
        ];
        update_option(self::QUARANTINE_KEY, $quarantined, false);
    }

    /**
     * Prune quarantine entries older than 14 days and cap at 100 rows.
     *
     * Call from a daily cron hook to prevent unbounded growth.
     */
    public static function pruneQuarantine(): void
    {
        /** @var array<int, array{bonus: float, queued_at: int, attempts: int, quarantined_at: int}> $quarantined */
        $quarantined = get_option(self::QUARANTINE_KEY, []);

        if (empty($quarantined)) {
            return;
        }

        $cutoff  = time() - (14 * DAY_IN_SECONDS);
        $pruned  = array_filter(
            $quarantined,
            static fn(array $entry): bool => ($entry['quarantined_at'] ?? 0) >= $cutoff
        );

        // Cap at 100 entries — keep the most recent by quarantined_at.
        if (count($pruned) > 100) {
            uasort($pruned, static fn(array $a, array $b): int => ($b['quarantined_at'] ?? 0) <=> ($a['quarantined_at'] ?? 0));
            $pruned = array_slice($pruned, 0, 100, true);
        }

        if (count($pruned) !== count($quarantined)) {
            update_option(self::QUARANTINE_KEY, $pruned, false);
        }
    }
}
