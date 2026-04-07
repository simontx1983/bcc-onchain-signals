<?php

namespace BCC\Onchain\Services;

use BCC\Core\Contracts\OnchainDataReadInterface;
use BCC\Core\DB\DB;
use BCC\Onchain\Repositories\ChainRepository;
use BCC\Onchain\Repositories\ValidatorRepository;
use BCC\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Implementation of OnchainDataReadInterface.
 *
 * Delegates to existing ValidatorRepository and CollectionService,
 * providing a cross-plugin contract for peepso-integration.
 */
final class OnchainDataReadService implements OnchainDataReadInterface
{
    public function getValidatorsForProject(int $projectId, int $page = 1, int $perPage = 8, string $orderBy = 'total_stake'): array
    {
        return ValidatorRepository::getForProject($projectId, $page, $perPage, $orderBy);
    }

    public function getCollectionsForProject(int $projectId, int $page = 1, int $perPage = 8, string $orderBy = 'total_volume', bool $includeHidden = false): array
    {
        return CollectionService::getForProject($projectId, $page, $perPage, $orderBy, $includeHidden);
    }

    public function getValidatorAggregateStats(int $projectId): array
    {
        global $wpdb;

        $table   = ValidatorRepository::table();
        $wallets = WalletRepository::table();
        $chains  = ChainRepository::table();

        // Single SQL query: SUM/COUNT instead of fetching all rows into PHP.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*)                                          AS chains_count,
                SUM(CASE WHEN v.status = 'active' THEN 1 ELSE 0 END) AS active_count,
                COALESCE(SUM(v.total_stake), 0)                   AS total_stake,
                COALESCE(SUM(v.delegator_count), 0)               AS total_delegators
             FROM {$table} v
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             WHERE w.post_id = %d",
            $projectId
        ));

        // Top validator by total_stake for supplementary display fields
        // (rank, uptime, governance). Single row, uses existing index.
        $top = $wpdb->get_row($wpdb->prepare(
            "SELECT v.*, c.slug AS chain_slug, c.name AS chain_name
             FROM {$table} v
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             JOIN {$chains} c ON c.id = v.chain_id
             WHERE w.post_id = %d
             ORDER BY v.total_stake DESC
             LIMIT 1",
            $projectId
        ));

        return [
            'active_count'     => $row ? (int) $row->active_count : 0,
            'chains_count'     => $row ? (int) $row->chains_count : 0,
            'total_stake'      => $row ? (float) $row->total_stake : 0.0,
            'total_delegators' => $row ? (int) $row->total_delegators : 0,
            'top_validator'    => $top,
        ];
    }

    public function getAllCollectionsForProject(int $projectId): array
    {
        return CollectionService::getAllForProject($projectId);
    }

    public function enrichCollectionsWithBadges(array $items, int $ownerId, int $viewerId = 0): array
    {
        return CollectionService::enrichWithBadges($items, $ownerId, $viewerId);
    }

    public function mergeCollectionsWithManual(array $onchainItems, array $manualRows): array
    {
        return CollectionService::mergeWithManual($onchainItems, $manualRows);
    }
}
