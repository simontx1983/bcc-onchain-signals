<?php

namespace BCC\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Contracts\WalletLinkWriteInterface;
use BCC\Onchain\Repositories\ChainRepository;
use BCC\Onchain\Repositories\WalletRepository;

/**
 * Write service for bcc_wallet_links, exposed via WalletLinkWriteInterface.
 *
 * Allows trust-engine to write wallet records to the canonical store
 * without direct cross-plugin table access.
 */
final class WalletLinkWriteService implements WalletLinkWriteInterface
{
    public function linkWallet(
        int $userId,
        string $chainSlug,
        string $walletAddress,
        int $postId = 0,
        string $walletType = 'user'
    ): int {
        // Resolve chain slug → chain_id
        $chain = ChainRepository::getBySlug($chainSlug);
        if (!$chain) {
            return 0;
        }

        $chainId = (int) $chain->id;

        // If wallet already exists, return existing ID
        if (WalletRepository::exists($userId, $chainId, $walletAddress)) {
            global $wpdb;
            $table = WalletRepository::table();
            $existingId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d AND chain_id = %d AND wallet_address = %s LIMIT 1",
                $userId,
                $chainId,
                $walletAddress
            ));
            return $existingId;
        }

        // Insert new wallet link
        $walletLinkId = WalletRepository::insert([
            'user_id'        => $userId,
            'post_id'        => $postId,
            'wallet_address' => $walletAddress,
            'chain_id'       => $chainId,
            'wallet_type'    => $walletType,
        ]);

        if ($walletLinkId) {
            // Mark as verified immediately (trust-engine already verified the sig)
            WalletRepository::verify($walletLinkId);

            // Auto-set primary if first wallet on this chain for this user
            $existing = WalletRepository::getForUser($userId);
            $chainCount = 0;
            foreach ($existing as $w) {
                if ((int) $w->chain_id === $chainId) {
                    $chainCount++;
                }
            }
            if ($chainCount <= 1) {
                WalletRepository::setPrimary($walletLinkId, $userId);
            }
        }

        return $walletLinkId;
    }

    public function unlinkWallet(int $userId, string $chainSlug, string $walletAddress): bool
    {
        $chain = ChainRepository::getBySlug($chainSlug);
        if (!$chain) {
            return false;
        }

        global $wpdb;
        $table = WalletRepository::table();

        $walletLinkId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND chain_id = %d AND wallet_address = %s LIMIT 1",
            $userId,
            (int) $chain->id,
            $walletAddress
        ));

        if (!$walletLinkId) {
            return false;
        }

        return WalletRepository::delete($walletLinkId, $userId);
    }
}
