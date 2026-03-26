<?php

namespace BCC\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Contracts\WalletLinkReadInterface;
use BCC\Onchain\Repositories\WalletRepository;
use BCC\Onchain\Repositories\ChainRepository;

/**
 * Exposes bcc_wallet_links data through the WalletLinkReadInterface contract.
 *
 * Registered via the bcc.resolve.wallet_link_read filter so that
 * trust-engine can merge AJAX-verified wallets into its read service
 * without querying another plugin's tables directly.
 */
final class WalletLinkReadService implements WalletLinkReadInterface
{
    public function getLinksForUser(int $userId): array
    {
        $rows = WalletRepository::getForUser($userId);

        $wallets = [];
        foreach ($rows as $row) {
            $chain = $row->chain_slug ?? '';
            $addr  = $row->wallet_address ?? '';
            if ($chain !== '' && $addr !== '') {
                $wallets[$chain][] = $addr;
            }
        }

        return $wallets;
    }

    public function hasLink(int $userId, string $chain): bool
    {
        $chainObj = ChainRepository::getBySlug($chain);
        if (!$chainObj) {
            return false;
        }

        global $wpdb;
        $table = WalletRepository::table();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE user_id = %d AND chain_id = %d LIMIT 1",
            $userId,
            (int) $chainObj->id
        ));
    }

    public function getUserIdsWithLinks(array $chains, int $limit = 100, int $offset = 0): array
    {
        if (empty($chains)) {
            return [];
        }

        global $wpdb;
        $table      = WalletRepository::table();
        $chainTable = ChainRepository::table();

        $slugPlaceholders = implode(',', array_fill(0, count($chains), '%s'));
        $args = array_merge($chains, [$limit, $offset]);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT w.user_id
             FROM {$table} w
             JOIN {$chainTable} c ON c.id = w.chain_id
             WHERE c.slug IN ({$slugPlaceholders})
             LIMIT %d OFFSET %d",
            ...$args
        ));

        return array_map('intval', $ids);
    }
}
