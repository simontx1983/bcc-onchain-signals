<?php

namespace BCC\Onchain\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Repositories\CollectionRepository;

/**
 * REST controller for NFT collection leaderboard data.
 *
 * Serves chain-separated collection data — each chain type
 * (evm, solana, cosmos) returns its own independent dataset.
 */
final class CollectionController
{
    private const ALLOWED_CHAINS   = ['evm', 'solana', 'cosmos'];
    private const ALLOWED_ORDER_BY = ['total_volume', 'floor_price', 'unique_holders', 'total_supply'];

    public static function registerRoutes(): void
    {
        register_rest_route('bcc/v1', '/nft/collections', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_collections'],
            'permission_callback' => '__return_true',
            'args'                => [
                'chain' => [
                    'required'          => true,
                    'type'              => 'string',
                    'enum'              => self::ALLOWED_CHAINS,
                    'sanitize_callback' => 'sanitize_key',
                ],
                'page' => [
                    'default'           => 1,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'default'           => 20,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
                'order_by' => [
                    'default'           => 'total_volume',
                    'type'              => 'string',
                    'enum'              => self::ALLOWED_ORDER_BY,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);
    }

    public static function get_collections(\WP_REST_Request $request): \WP_REST_Response
    {
        $chain   = $request->get_param('chain');
        $page    = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');
        $orderBy = $request->get_param('order_by');

        $data = CollectionRepository::getTopCollectionsByChainType($chain, $page, $perPage, $orderBy);

        return new \WP_REST_Response([
            'items'  => $data['items'],
            'total'  => $data['total'],
            'pages'  => $data['pages'],
            'chain'  => $chain,
            'page'   => $page,
        ], 200);
    }
}
