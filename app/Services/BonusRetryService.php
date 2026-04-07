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
    private const MAX_ATTEMPTS  = 5;

    /**
     * Queue a failed bonus application for retry.
     */
    public static function queue(int $pageId, float $bonus): void
    {
        $pending = get_option(self::OPTION_KEY, []);
        $pending[$pageId] = [
            'bonus'     => $bonus,
            'queued_at' => time(),
            'attempts'  => ($pending[$pageId]['attempts'] ?? 0) + 1,
        ];
        update_option(self::OPTION_KEY, $pending, false);

        if (class_exists('BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[bcc-onchain-signals] bonus_queued_for_retry', [
                'page_id'  => $pageId,
                'bonus'    => $bonus,
                'attempts' => $pending[$pageId]['attempts'],
            ]);
        }
    }

    /**
     * Clear a page from the pending retry queue (called on success).
     */
    public static function clear(int $pageId): void
    {
        $pending = get_option(self::OPTION_KEY, []);
        if (isset($pending[$pageId])) {
            unset($pending[$pageId]);
            update_option(self::OPTION_KEY, $pending, false);
        }
    }

    /**
     * Process all pending bonus retries.
     *
     * Idempotent: recalculates from stored signals (source of truth).
     */
    public static function processAll(): void
    {
        $pending = get_option(self::OPTION_KEY, []);
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

        foreach ($pending as $pageId => $entry) {
            if (($entry['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
                if (class_exists('BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-onchain-signals] bonus_retry_exhausted', [
                        'page_id'  => $pageId,
                        'attempts' => $entry['attempts'],
                    ]);
                }
                unset($pending[$pageId]);
                continue;
            }

            $allSignals  = SignalRepository::get_for_page((int) $pageId);
            $totalBonus  = array_sum(array_column($allSignals, 'score_contribution'));
            $totalBonus  = min($totalBonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);

            $applied = $contributor->applyBonus((int) $pageId, 'onchain', $totalBonus);

            if ($applied) {
                unset($pending[$pageId]);
            } else {
                $pending[$pageId]['attempts'] = ($entry['attempts'] ?? 0) + 1;
            }
        }

        update_option(self::OPTION_KEY, $pending, false);
    }
}
