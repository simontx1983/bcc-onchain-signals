<?php

namespace BCC\Onchain\Controllers;

use BCC\Onchain\Repositories\SignalRepository;
use BCC\Onchain\Services\SignalRefreshService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API controller for on-chain signal data.
 *
 * Routes:
 *   GET  /bcc/v1/onchain/{page_id}         — fetch stored signals
 *   POST /bcc/v1/onchain/{page_id}/refresh  — admin force re-fetch
 */
final class SignalController
{
    /**
     * Register REST routes.
     */
    public static function registerRoutes(): void
    {
        register_rest_route('bcc/v1', '/onchain/(?P<page_id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [self::class, 'get'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        register_rest_route('bcc/v1', '/onchain/(?P<page_id>\d+)/refresh', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'refresh'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
    }

    /**
     * GET /bcc/v1/onchain/{page_id}
     */
    public static function get(\WP_REST_Request $req): \WP_REST_Response
    {
        $pageId = (int) $req->get_param('page_id');
        $data   = SignalRepository::get_for_page($pageId);
        return rest_ensure_response($data);
    }

    /**
     * POST /bcc/v1/onchain/{page_id}/refresh
     */
    public static function refresh(\WP_REST_Request $req): \WP_REST_Response
    {
        $pageId  = (int) $req->get_param('page_id');
        $results = SignalRefreshService::fetchAndStoreForPage($pageId, true);
        return rest_ensure_response(['refreshed' => count($results), 'signals' => $results]);
    }
}
