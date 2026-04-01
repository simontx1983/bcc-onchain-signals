<?php

namespace BCC\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Repositories\CollectionRepository;

/**
 * Service layer for NFT collection data.
 *
 * Wraps CollectionRepository with object-cache (Redis when available)
 * so repeated reads within a request or across requests are free.
 */
final class CollectionService
{
    private const CACHE_GROUP = 'bcc_onchain_collections';
    private const DEFAULT_TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * Get paginated collections for a project (shadow CPT post ID).
     *
     * @param int    $projectId  Shadow CPT post_id (e.g. nft post).
     * @param int    $page       Page number (1-based).
     * @param int    $perPage    Items per page.
     * @param string $orderBy    Column to sort by.
     * @return array{items: array, total: int, pages: int}
     */
    /**
     * @param bool $includeHidden If true, returns hidden collections too (for owner dashboard).
     */
    public static function getForProject(int $projectId, int $page = 1, int $perPage = 8, string $orderBy = 'total_volume', bool $includeHidden = false): array
    {
        $hiddenFlag = $includeHidden ? '1' : '0';
        $cacheKey   = "project_{$projectId}_{$page}_{$perPage}_{$orderBy}_h{$hiddenFlag}";
        $cached     = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if (false !== $cached) {
            return $cached;
        }

        $result = CollectionRepository::getForProject($projectId, $page, $perPage, $orderBy, $includeHidden);

        wp_cache_set($cacheKey, $result, self::CACHE_GROUP, self::ttl());

        return $result;
    }

    /**
     * Get ALL collections for a project (for aggregation).
     *
     * Cached separately from paginated results since this is used
     * to compute aggregate stats (total volume, avg floor, etc.).
     * Only counts visible collections (show_on_profile = 1).
     *
     * @param int $projectId
     * @return array{items: array, total: int, pages: int}
     */
    public static function getAllForProject(int $projectId): array
    {
        $cacheKey = "project_{$projectId}_all";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if (false !== $cached) {
            return $cached;
        }

        $result = CollectionRepository::getForProject($projectId, 1, 999, 'total_volume', false);

        wp_cache_set($cacheKey, $result, self::CACHE_GROUP, self::ttl());

        return $result;
    }

    /**
     * Enrich collection items with badge flags for a given viewer and page owner.
     *
     * Adds to each item object:
     *   ->is_creator    bool  True if the page owner created/deployed this collection.
     *   ->viewer_holds  bool  True if the viewing user holds NFTs from this collection.
     *
     * @param array  $items     Collection item objects (from getForProject).
     * @param int    $ownerId   Page owner user ID (creator badge).
     * @param int    $viewerId  Current viewer user ID (holder badge). 0 = logged out.
     * @return array Same items with badge flags added.
     */
    public static function enrichWithBadges(array $items, int $ownerId, int $viewerId = 0): array
    {
        if (empty($items)) {
            return $items;
        }

        // All collections on this page belong to the page owner's wallets,
        // so the owner IS the creator for every collection shown here.
        // The is_creator flag is true for all items on this page.

        // Build contract list for holder check
        $contracts = [];
        foreach ($items as $item) {
            $contracts[] = $item->contract_address ?? '';
        }
        $contracts = array_filter($contracts);

        // Batch check viewer holdings (one query, cached per request)
        $viewerHoldings = [];
        if ($viewerId && $viewerId !== $ownerId && !empty($contracts)) {
            $cacheKey = "holdings_{$viewerId}_" . md5(implode(',', $contracts));
            $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);

            if (false !== $cached) {
                $viewerHoldings = $cached;
            } else {
                $viewerHoldings = CollectionRepository::getUserHoldings($viewerId, $contracts);
                wp_cache_set($cacheKey, $viewerHoldings, self::CACHE_GROUP, self::ttl());
            }
        }

        foreach ($items as $item) {
            $addr = strtolower($item->contract_address ?? '');

            // Creator: the page owner deployed this collection (it's on their page)
            $item->is_creator = true;

            // Holder: the viewer has a wallet that interacted with this contract
            $item->viewer_holds = ($viewerId && $viewerId !== $ownerId)
                ? ($viewerHoldings[$addr] ?? false)
                : false;
        }

        return $items;
    }

    /**
     * Merge on-chain collections with manual ACF repeater rows into a
     * single deduplicated list, each tagged with its data source.
     *
     * Deduplication: if a manual row has a contract_address that matches
     * an on-chain row, the on-chain row wins (verified data) and the
     * manual row is dropped. Manual rows without a contract_address or
     * whose address isn't found on-chain are kept and tagged 'self-reported'.
     *
     * Each returned item is a stdClass with at minimum:
     *   ->collection_name, ->contract_address, ->data_source ('onchain'|'self-reported')
     *   On-chain items retain all DB columns. Self-reported items have ACF subfield values.
     *
     * @param array $onchainItems  Items from CollectionService::getForProject() (objects).
     * @param array $manualRows    ACF repeater rows (assoc arrays from get_field()).
     * @return array Merged list of stdClass objects.
     */
    public static function mergeWithManual(array $onchainItems, array $manualRows): array
    {
        // Index on-chain items by normalized compound key: address + chain_slug.
        // This prevents false matches when the same contract address exists
        // on different chains (e.g., bridged/proxied contracts on Ethereum vs Polygon).
        //
        // Also index by address-only as fallback for manual rows that don't
        // specify a chain (the common case — most manual entries omit chain).
        $onchainIndex = [];
        foreach ($onchainItems as $item) {
            $addr      = strtolower(trim($item->contract_address ?? ''));
            $chainSlug = strtolower(trim($item->chain_slug ?? ''));
            if ($addr !== '') {
                if ($chainSlug !== '') {
                    $onchainIndex[$addr . ':' . $chainSlug] = true;
                }
                $onchainIndex[$addr] = true;
            }
            $item->data_source = 'onchain';
        }

        $merged = $onchainItems;

        // Walk manual rows — only add if NOT already covered by on-chain.
        // Manual rows store chain as a display name or network post ID,
        // so we normalize to lowercase for comparison against chain_slug.
        foreach ($manualRows as $row) {
            $manualAddr  = strtolower(trim($row['collection_contract_address'] ?? ''));
            $manualChain = strtolower(trim($row['collection_chain'] ?? ''));

            if ($manualAddr !== '') {
                // Try compound key (address:chain) first, then address-only
                $compoundKey = ($manualChain !== '') ? $manualAddr . ':' . $manualChain : '';
                if (($compoundKey !== '' && isset($onchainIndex[$compoundKey]))
                    || isset($onchainIndex[$manualAddr])) {
                    // Duplicate — on-chain version wins. Skip the manual row.
                    continue;
                }
            }

            // Convert manual ACF row to object with same shape for the renderer
            $manual = (object) [
                'id'                 => null,
                'contract_address'   => $row['collection_contract_address'] ?? '',
                'collection_name'    => $row['collection_name'] ?? '',
                'chain_name'         => $row['collection_chain'] ?? '',
                'chain_slug'         => '',
                'explorer_url'       => '',
                'native_token'       => '',
                'token_standard'     => null,
                'total_supply'       => $row['collection_total_supply'] ?? null,
                'floor_price'        => $row['collection_floor_price'] ?? null,
                'floor_currency'     => null,
                'total_volume'       => null,
                'unique_holders'     => $row['collection_unique_holders'] ?? null,
                'listed_percentage'  => $row['collection_listed_percentage'] ?? null,
                'royalty_percentage' => $row['collection_royalty_rate'] ?? null,
                'metadata_storage'   => null,
                'show_on_profile'    => 1,
                'fetched_at'         => null,
                'data_source'        => 'self-reported',
                'is_creator'         => true,
                'viewer_holds'       => false,
                'can_toggle'         => false,
            ];

            $merged[] = $manual;
        }

        return $merged;
    }

    /**
     * Toggle whether a collection appears on the owner's profile.
     *
     * @param int  $collectionId  Row ID in onchain_collections.
     * @param int  $userId        Must own the wallet_link.
     * @param bool $show          True to show, false to hide.
     * @return bool True if updated successfully.
     */
    public static function toggleProfileVisibility(int $collectionId, int $userId, bool $show): bool
    {
        $result = CollectionRepository::setShowOnProfile($collectionId, $userId, $show);

        if ($result) {
            // Find the project_id to invalidate cache
            global $wpdb;
            $table   = CollectionRepository::table();
            $wallets = \BCC\Onchain\Repositories\WalletRepository::table();

            $postId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT w.post_id
                 FROM {$table} c
                 JOIN {$wallets} w ON w.id = c.wallet_link_id
                 WHERE c.id = %d LIMIT 1",
                $collectionId
            ));

            if ($postId) {
                self::invalidate($postId);
            }
        }

        return $result;
    }

    /**
     * Invalidate all cached collection data for a project.
     *
     * Called after upsert operations and visibility toggles to ensure
     * stale data is never served.
     */
    public static function invalidate(int $projectId): void
    {
        // wp_cache doesn't support group-level deletion on all backends,
        // so we delete known keys. The paginated keys will naturally expire.
        wp_cache_delete("project_{$projectId}_all", self::CACHE_GROUP);

        // Bust the first page (most commonly accessed) — both visibility variants.
        wp_cache_delete("project_{$projectId}_1_8_total_volume_h0", self::CACHE_GROUP);
        wp_cache_delete("project_{$projectId}_1_8_total_volume_h1", self::CACHE_GROUP);

        // Legacy key (before show_on_profile filtering was added).
        wp_cache_delete("project_{$projectId}_1_8_total_volume", self::CACHE_GROUP);
    }

    private static function ttl(): int
    {
        return (int) apply_filters('bcc_onchain_collection_cache_ttl', self::DEFAULT_TTL);
    }
}
