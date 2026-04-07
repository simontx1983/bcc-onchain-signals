<?php

namespace BCC\Onchain\Services;

use BCC\Core\PeepSo\PeepSo;
use BCC\Core\ServiceLocator;
use BCC\Onchain\Repositories\SignalRepository;
use BCC\Onchain\Support\ChainSupport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetches, stores, and scores on-chain signal data for wallets.
 *
 * Handles both per-page refreshes (all wallets) and per-wallet refreshes
 * (single newly-verified wallet). Delegates bonus application to BonusService.
 */
final class SignalRefreshService
{
    /**
     * Fetch (or load from cache) on-chain signals for all wallets connected
     * to the owner of $pageId, store them, and return the rows.
     *
     * @return array Array of signal rows.
     */
    public static function fetchAndStoreForPage(int $pageId, bool $force = false): array
    {
        $ownerId = PeepSo::get_page_owner($pageId);
        if (!$ownerId) {
            return [];
        }

        $wallets = SignalFetcher::get_connected_wallets($ownerId);
        $results = [];

        foreach ($wallets as $chain => $addresses) {
            foreach ($addresses as $address) {
                $cached = $force ? null : SignalRepository::get_cached($address, $chain);

                if ($cached) {
                    $results[] = $cached;
                    continue;
                }

                $signals = SignalFetcher::fetch($address, $chain, $force);
                if ($signals === null) {
                    continue;
                }

                $score = SignalScorer::score($signals);
                $row   = array_merge($signals, [
                    'user_id'            => $ownerId,
                    'wallet_address'     => $address,
                    'chain'              => $chain,
                    'score_contribution' => $score,
                ]);

                SignalRepository::upsert($row);
                $results[] = $row;
            }
        }

        if (!empty($results)) {
            $totalBonus = array_sum(array_column($results, 'score_contribution'));
            $totalBonus = min($totalBonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);
            BonusService::applyBonus($pageId, $totalBonus);
        }

        return $results;
    }

    /**
     * Fetch and store signals for a single wallet, then recalculate the page bonus.
     *
     * @return array|null Signal row, or null on API error.
     */
    public static function fetchAndStoreWallet(int $userId, int $pageId, string $chain, string $address, bool $force = false): ?array
    {
        $cached = $force ? null : SignalRepository::get_cached($address, $chain);
        if ($cached) {
            return $cached;
        }

        $signals = SignalFetcher::fetch($address, $chain, $force);
        if ($signals === null) {
            return null;
        }

        $score = SignalScorer::score($signals);
        $row   = array_merge($signals, [
            'user_id'            => $userId,
            'wallet_address'     => $address,
            'chain'              => $chain,
            'score_contribution' => $score,
        ]);

        SignalRepository::upsert($row);

        // Recalculate total bonus from all wallets for this page
        $allSignals = SignalRepository::get_for_page($pageId);
        $totalBonus = array_sum(array_column($allSignals, 'score_contribution'));
        $totalBonus = min($totalBonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);
        BonusService::applyBonus($pageId, $totalBonus);

        return $row;
    }

    /**
     * Daily cron: refresh signals for all pages with wallets.
     *
     * Hooked to: bcc_onchain_daily_refresh
     */
    public static function dailyRefresh(): void
    {
        // Also process any pending bonus retries.
        BonusRetryService::processAll();

        $walletService = ServiceLocator::resolveWalletLinkRead();
        $supported     = ChainSupport::supported();
        $batchSize     = 100;
        $offset        = 0;
        $stagger       = 0;

        do {
            $owners = $walletService->getUserIdsWithLinks($supported, $batchSize, $offset);

            if (empty($owners)) {
                break;
            }

            foreach ($owners as $ownerId) {
                $pageId = ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($ownerId);

                if ($pageId && !wp_next_scheduled('bcc_onchain_refresh_page', [$pageId])) {
                    wp_schedule_single_event(time() + (10 * $stagger), 'bcc_onchain_refresh_page', [$pageId]);
                    $stagger++;
                }
            }

            $offset += $batchSize;
        } while (count($owners) === $batchSize);
    }

    /**
     * Per-page cron: refresh signals for a single page.
     *
     * Hooked to: bcc_onchain_refresh_page
     */
    public static function refreshPage(int $pageId): void
    {
        self::fetchAndStoreForPage($pageId, false);
    }
}
