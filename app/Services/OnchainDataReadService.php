<?php

namespace BCC\Onchain\Services;

use BCC\Core\Contracts\OnchainDataReadInterface;
use BCC\Onchain\Repositories\ValidatorRepository;

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
        $row = ValidatorRepository::getAggregateStatsForProject($projectId);
        $top = ValidatorRepository::getTopValidatorForProject($projectId);

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
