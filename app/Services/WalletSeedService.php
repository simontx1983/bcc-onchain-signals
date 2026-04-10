<?php

namespace BCC\Onchain\Services;

use BCC\Onchain\Factories\FetcherFactory;
use BCC\Onchain\Repositories\ChainRepository;
use BCC\Onchain\Repositories\CollectionRepository;
use BCC\Onchain\Repositories\SignalRepository;
use BCC\Onchain\Repositories\ValidatorRepository;
use BCC\Onchain\Repositories\WalletRepository;
use BCC\Onchain\Support\ChainSupport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Seeds on-chain data when a user first verifies a wallet.
 *
 * Populates signal data, validator data (Cosmos), and NFT collection
 * data so the UI is never empty immediately after verification.
 *
 * Hooked to: bcc_wallet_verified
 */
final class WalletSeedService
{
    /**
     * Handle the bcc_wallet_verified action.
     */
    public static function onWalletVerified(int $userId, string $chain, string $address): void
    {
        // Per-request guard: prevent double processing.
        static $processed = [];
        $key = $userId . ':' . $chain . ':' . strtolower($address);
        if (isset($processed[$key])) {
            return;
        }
        $processed[$key] = true;

        if (!in_array($chain, ChainSupport::supported(), true)) {
            return;
        }

        // If we already have a stored row for this wallet, skip the API call.
        if (SignalRepository::get_permanent($address, $chain)) {
            return;
        }

        self::seedSignals($userId, $chain, $address);
        self::seedEntities($userId, $chain, $address);
    }

    /**
     * Fetch and store signal data for a newly verified wallet.
     */
    private static function seedSignals(int $userId, string $chain, string $address): void
    {
        $pageId = \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($userId);

        if ($pageId) {
            SignalRefreshService::fetchAndStoreWallet($userId, $pageId, $chain, $address);
        } else {
            $signals = SignalFetcher::fetch($address, $chain);
            if ($signals !== null) {
                SignalRepository::upsert(array_merge($signals, [
                    'user_id'            => $userId,
                    'wallet_address'     => $address,
                    'chain'              => $chain,
                    'score_contribution' => min(SignalScorer::score($signals), BCC_ONCHAIN_MAX_TOTAL_BONUS),
                ]));
            }
        }
    }

    /**
     * Seed validator and collection data for a newly verified wallet.
     *
     * Idempotent: skips API calls if entities already exist for this wallet_link.
     * Uses an advisory lock to prevent concurrent cron invocations from double-seeding.
     */
    private static function seedEntities(int $userId, string $chain, string $address): void
    {
        $chainObj = ChainRepository::getBySlug($chain);
        if (!$chainObj || !FetcherFactory::has_driver($chainObj->chain_type)) {
            return;
        }

        $fetcher = FetcherFactory::make_for_chain($chainObj);

        // Find the wallet_link row for this address.
        $walletLink = null;
        $wallets = WalletRepository::getForUser($userId);
        foreach ($wallets as $w) {
            if (strtolower($w->wallet_address) === strtolower($address)) {
                $walletLink = $w;
                break;
            }
        }

        if (!$walletLink) {
            return;
        }

        $walletLinkId = (int) $walletLink->id;

        // Advisory lock prevents concurrent cron runs from double-seeding the same wallet.
        $lockKey = 'bcc_seed_entities_' . $walletLinkId;
        if (!\BCC\Onchain\Repositories\LockRepository::acquire($lockKey, 0)) {
            return;
        }

        try {
            // Seed validator data (Cosmos chains) — skip if already seeded.
            if ($fetcher->supports_feature('validator')) {
                if (!ValidatorRepository::existsForWalletLink($walletLinkId)) {
                    $validatorData = $fetcher->fetch_validator($address);
                    if (!empty($validatorData)) {
                        ValidatorRepository::upsert($validatorData, $walletLinkId, HOUR_IN_SECONDS);
                    }
                }
            }

            // Seed NFT collection data (all chains) — skip if already seeded.
            if ($fetcher->supports_feature('collection')) {
                if (!CollectionRepository::existsForWalletLink($walletLinkId)) {
                    $collections = $fetcher->fetch_collections($address, (int) $chainObj->id);
                    foreach ($collections as $c) {
                        CollectionRepository::upsert($c, $walletLinkId, 4 * HOUR_IN_SECONDS);
                    }
                    if (!empty($collections) && (int) $walletLink->post_id > 0) {
                        CollectionService::invalidate((int) $walletLink->post_id);
                    }
                }
            }
        } finally {
            \BCC\Onchain\Repositories\LockRepository::release($lockKey);
        }
    }
}
