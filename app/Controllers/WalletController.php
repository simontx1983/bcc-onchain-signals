<?php

namespace BCC\Onchain\Controllers;

use BCC\Onchain\Repositories\ChainRepository;
use BCC\Onchain\Repositories\WalletRepository;
use BCC\Onchain\Services\CollectionService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wallet Connect & Verify
 *
 * Handles the full wallet lifecycle:
 *  1. Generate a nonce/challenge message
 *  2. User signs with wallet (MetaMask / Keplr / Phantom)
 *  3. Server verifies signature → inserts wallet_link row → marks verified
 *  4. CRUD: list, set-primary, disconnect
 *
 * Signature verification delegates to \BCC\Core\Crypto\WalletVerifier.
 */
class WalletController
{
    const CHALLENGE_PREFIX = "Sign this message to verify your wallet on Blue Collar Crypto. Nonce: ";
    const CHALLENGE_TTL = 300;

    /**
     * Boot hooks.
     */
    public static function init(): void
    {
        add_action('wp_ajax_bcc_wallet_challenge',    [__CLASS__, 'ajax_challenge']);
        add_action('wp_ajax_bcc_wallet_verify',       [__CLASS__, 'ajax_verify']);
        add_action('wp_ajax_bcc_wallet_disconnect',   [__CLASS__, 'ajax_disconnect']);
        add_action('wp_ajax_bcc_wallet_set_primary',  [__CLASS__, 'ajax_set_primary']);
        add_action('wp_ajax_bcc_wallet_list',         [__CLASS__, 'ajax_list']);
        add_action('wp_ajax_bcc_collection_toggle_profile', [__CLASS__, 'ajax_toggle_collection_profile']);
        add_action('wp_ajax_bcc_claim_entity', [__CLASS__, 'ajax_claim_entity']);
        add_action('wp_ajax_bcc_claim_status', [__CLASS__, 'ajax_claim_status']);

        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    // ── AJAX: Generate Challenge ─────────────────────────────────────────────

    public static function ajax_challenge(): void
    {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'Not logged in.'], 401);
        }

        $chain_slug     = sanitize_text_field($_POST['chain_slug'] ?? '');
        $wallet_address = sanitize_text_field($_POST['wallet_address'] ?? '');

        if (!$chain_slug || !$wallet_address) {
            wp_send_json_error(['message' => 'Missing chain or address.'], 400);
        }

        $chain_id = ChainRepository::resolveId($chain_slug);
        if (!$chain_id) {
            wp_send_json_error(['message' => 'Unsupported chain.'], 400);
        }

        $nonce   = wp_generate_password(32, false);
        $message = self::CHALLENGE_PREFIX . $nonce;

        $challenge_data = [
            'nonce'          => $nonce,
            'message'        => $message,
            'chain_slug'     => $chain_slug,
            'chain_id'       => $chain_id,
            'wallet_address' => $wallet_address,
            'expires_at'     => time() + self::CHALLENGE_TTL,
        ];
        update_user_meta($user_id, self::challengeKey($user_id, $wallet_address), $challenge_data);

        wp_send_json_success([
            'message' => $message,
            'nonce'   => $nonce,
        ]);
    }

    // ── AJAX: Verify Signature ───────────────────────────────────────────────

    public static function ajax_verify(): void
    {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'Not logged in.'], 401);
        }

        $wallet_address = sanitize_text_field($_POST['wallet_address'] ?? '');
        $post_id        = (int) ($_POST['post_id'] ?? 0);
        $wallet_type    = sanitize_text_field($_POST['wallet_type'] ?? 'user');
        $label          = sanitize_text_field($_POST['label'] ?? '');

        $raw_sig   = wp_unslash($_POST['signature'] ?? '');
        $signature = wp_strip_all_tags($raw_sig);

        if (!$wallet_address || !$signature) {
            wp_send_json_error(['message' => 'Missing address or signature.'], 400);
        }

        $meta_key  = self::challengeKey($user_id, $wallet_address);
        $challenge = get_user_meta($user_id, $meta_key, true);

        if (!$challenge || !is_array($challenge)) {
            wp_send_json_error(['message' => 'Challenge not found. Please try again.'], 400);
        }

        if (time() > ($challenge['expires_at'] ?? 0)) {
            delete_user_meta($user_id, $meta_key);
            wp_send_json_error(['message' => 'Challenge expired. Please try again.'], 400);
        }

        $chain = ChainRepository::getById((int) $challenge['chain_id']);
        if (!$chain) {
            wp_send_json_error(['message' => 'Chain not found.'], 400);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BCC Wallet Connect: Verifying chain_type=' . $chain->chain_type
                . ' address=' . $wallet_address
                . ' sig_length=' . strlen($signature)
                . ' sig_starts=' . substr($signature, 0, 50));
        }

        $valid = \BCC\Core\Crypto\WalletVerifier::verify(
            $chain->chain_type,
            $challenge['message'],
            $signature,
            $wallet_address
        );

        if (!$valid) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BCC Wallet Connect: Verification FAILED for chain_type=' . $chain->chain_type);
            }
            wp_send_json_error(['message' => 'Signature verification failed.'], 403);
        }

        delete_user_meta($user_id, $meta_key);

        // Atomic insert-or-find: uses INSERT ... ON DUPLICATE KEY UPDATE
        // against the UNIQUE KEY (user_id, chain_id, wallet_address).
        // Eliminates the TOCTOU race between exists() check and insert().
        $result = WalletRepository::insertOrFind([
            'user_id'        => $user_id,
            'post_id'        => $post_id,
            'wallet_address' => $wallet_address,
            'chain_id'       => (int) $chain->id,
            'wallet_type'    => $wallet_type,
            'label'          => $label,
        ]);

        $wallet_link_id = $result['id'];

        if (!$wallet_link_id) {
            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-onchain-signals] wallet_insert_failed', [
                    'user_id'  => $user_id,
                    'chain_id' => (int) $chain->id,
                ]);
            }
            wp_send_json_error(['message' => 'Failed to save wallet.'], 500);
        }

        if (!$result['inserted']) {
            // Wallet already existed — concurrent request or re-verification.
            // Return 409 so the frontend knows it's a duplicate.
            wp_send_json_error(['message' => 'This wallet is already linked to your account.'], 409);
        }

        WalletRepository::verify($wallet_link_id);

        $user_wallets  = WalletRepository::getForUser($user_id);
        $chain_wallets = array_filter($user_wallets, function ($w) use ($chain) {
            return (int) $w->chain_id === (int) $chain->id;
        });
        if (count($chain_wallets) <= 1) {
            WalletRepository::setPrimary($wallet_link_id, $user_id);
        }

        do_action('bcc_wallet_verified', $user_id, $chain->slug, $wallet_address);

        wp_send_json_success([
            'wallet_link_id' => $wallet_link_id,
            'chain'          => $chain->slug,
            'chain_name'     => $chain->name,
            'address'        => $wallet_address,
            'wallet_type'    => $wallet_type,
            'verified'       => true,
        ]);
    }

    // ── AJAX: Disconnect Wallet ──────────────────────────────────────────────

    public static function ajax_disconnect(): void
    {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id        = get_current_user_id();
        $wallet_link_id = (int) ($_POST['wallet_link_id'] ?? 0);

        if (!$user_id || !$wallet_link_id) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $deleted = WalletRepository::delete($wallet_link_id, $user_id);

        if (!$deleted) {
            wp_send_json_error(['message' => 'Wallet not found or not yours.'], 404);
        }

        wp_send_json_success(['deleted' => $wallet_link_id]);
    }

    // ── AJAX: Set Primary ────────────────────────────────────────────────────

    public static function ajax_set_primary(): void
    {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id        = get_current_user_id();
        $wallet_link_id = (int) ($_POST['wallet_link_id'] ?? 0);

        if (!$user_id || !$wallet_link_id) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $result = WalletRepository::setPrimary($wallet_link_id, $user_id);

        if (!$result) {
            wp_send_json_error(['message' => 'Wallet not found or not yours.'], 404);
        }

        wp_send_json_success(['primary' => $wallet_link_id]);
    }

    // ── AJAX: List Wallets ───────────────────────────────────────────────────

    public static function ajax_list(): void
    {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'Not logged in.'], 401);
        }

        $wallets = WalletRepository::getForUser($user_id);

        wp_send_json_success([
            'wallets' => array_map(function ($w) {
                return [
                    'id'             => (int) $w->id,
                    'wallet_address' => $w->wallet_address,
                    'chain_slug'     => $w->chain_slug,
                    'chain_name'     => $w->chain_name,
                    'chain_type'     => $w->chain_type,
                    'explorer_url'   => $w->explorer_url,
                    'wallet_type'    => $w->wallet_type,
                    'label'          => $w->label,
                    'is_primary'     => (bool) $w->is_primary,
                    'verified'       => !empty($w->verified_at),
                    'created_at'     => $w->created_at,
                ];
            }, $wallets),
        ]);
    }

    // ── REST API Routes ──────────────────────────────────────────────────────

    public static function register_rest_routes(): void
    {
        register_rest_route('bcc/v1', '/wallets', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_list_wallets'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        register_rest_route('bcc/v1', '/wallets/project/(?P<post_id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_project_wallets'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        register_rest_route('bcc/v1', '/chains', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_list_chains'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function rest_list_wallets(\WP_REST_Request $req): \WP_REST_Response
    {
        $wallets = WalletRepository::getForUser(get_current_user_id());
        return rest_ensure_response($wallets);
    }

    public static function rest_project_wallets(\WP_REST_Request $req): \WP_REST_Response
    {
        $post_id = (int) $req->get_param('post_id');
        $wallets = WalletRepository::getForProject($post_id);
        return rest_ensure_response($wallets);
    }

    public static function rest_list_chains(\WP_REST_Request $req): \WP_REST_Response
    {
        $chains = ChainRepository::getActive();
        return rest_ensure_response($chains);
    }

    // ── Signature Verification ───────────────────────────────────────────────
    // All crypto verification is handled by \BCC\Core\Crypto\WalletVerifier.

    // ── Frontend Assets ──────────────────────────────────────────────────────

    public static function enqueue_assets(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        wp_enqueue_script(
            'bcc-wallet-connect',
            BCC_ONCHAIN_URL . 'assets/js/bcc-wallet-connect.js',
            [],
            BCC_ONCHAIN_VERSION,
            true
        );

        wp_localize_script('bcc-wallet-connect', 'bccWallet', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bcc_wallet_nonce'),
            'chains'  => self::getChainsForJs(),
            'i18n'    => [
                'connect'        => __('Connect Wallet', 'bcc-onchain'),
                'disconnect'     => __('Disconnect', 'bcc-onchain'),
                'verify'         => __('Verify Ownership', 'bcc-onchain'),
                'signing'        => __('Signing…', 'bcc-onchain'),
                'verifying'      => __('Verifying…', 'bcc-onchain'),
                'verified'       => __('Verified', 'bcc-onchain'),
                'failed'         => __('Verification failed', 'bcc-onchain'),
                'expired'        => __('Challenge expired, try again', 'bcc-onchain'),
                'no_wallet'      => __('No wallet detected', 'bcc-onchain'),
                'already_linked' => __('This wallet is already linked', 'bcc-onchain'),
            ],
        ]);

        wp_enqueue_style(
            'bcc-wallet-connect',
            BCC_ONCHAIN_URL . 'assets/css/bcc-wallet-connect.css',
            [],
            BCC_ONCHAIN_VERSION
        );
    }

    // ── AJAX: Toggle Collection Profile Visibility ─────────────────────────

    public static function ajax_toggle_collection_profile(): void
    {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id       = get_current_user_id();
        $collection_id = (int) ($_POST['collection_id'] ?? 0);
        $show          = filter_var($_POST['show'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if (!$user_id || !$collection_id) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $updated = CollectionService::toggleProfileVisibility($collection_id, $user_id, $show);

        if (!$updated) {
            wp_send_json_error(['message' => 'Collection not found or not yours.'], 404);
        }

        wp_send_json_success(['collection_id' => $collection_id, 'show_on_profile' => $show]);
    }

    // ── Claim AJAX ──────────────────────────────────────────────────────────

    /**
     * AJAX: Claim an on-chain entity (validator/collection).
     * Verifies user's connected wallet matches the entity's on-chain owner.
     */
    public static function ajax_claim_entity(): void
    {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id     = get_current_user_id();
        $entity_type = sanitize_key($_POST['entity_type'] ?? '');
        $entity_id   = (int) ($_POST['entity_id'] ?? 0);

        if (!$user_id) {
            wp_send_json_error(['message' => 'Authentication required.'], 401);
        }

        if (!$entity_type || !$entity_id) {
            wp_send_json_error(['message' => 'Missing entity type or ID.'], 400);
        }

        $result = \BCC\Onchain\Services\ClaimService::claim($user_id, $entity_type, $entity_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            $code = !empty($result['needs_wallet']) ? 412 : 400;
            wp_send_json_error($result, $code);
        }
    }

    /**
     * AJAX: Check claim status for an entity (used by block to show badges).
     */
    public static function ajax_claim_status(): void
    {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $entity_type = sanitize_key($_GET['entity_type'] ?? $_POST['entity_type'] ?? '');
        $entity_id   = (int) ($_GET['entity_id'] ?? $_POST['entity_id'] ?? 0);

        if (!$entity_type || !$entity_id) {
            wp_send_json_error(['message' => 'Missing parameters.'], 400);
        }

        // Single query — getForEntity() returns all verified claims with user names.
        // Extract the current user's claim from the same result set instead of a second query.
        $claims  = \BCC\Onchain\Repositories\ClaimRepository::getForEntity($entity_type, $entity_id);
        $user_id = get_current_user_id();

        $user_claim = null;
        if ($user_id) {
            foreach ($claims as $c) {
                if ((int) $c->user_id === $user_id) {
                    $user_claim = $c;
                    break;
                }
            }
        }

        wp_send_json_success([
            'claims'     => array_map(function ($c) {
                return [
                    'user_id'      => (int) $c->user_id,
                    'claimer_name' => $c->claimer_name ?? '',
                    'role'         => $c->claim_role,
                    'verified_at'  => $c->verified_at,
                ];
            }, $claims),
            'user_claim' => $user_claim ? [
                'id'     => (int) $user_claim->id,
                'role'   => $user_claim->claim_role,
                'status' => $user_claim->status,
            ] : null,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function challengeKey(int $user_id, string $address): string
    {
        return 'bcc_wallet_challenge_' . $user_id . '_' . md5(strtolower($address));
    }

    private static function getChainsForJs(): array
    {
        $chains = ChainRepository::getActive();
        $result = [];

        foreach ($chains as $chain) {
            $result[] = [
                'id'           => (int) $chain->id,
                'slug'         => $chain->slug,
                'name'         => $chain->name,
                'chain_type'   => $chain->chain_type,
                'chain_id_hex' => $chain->chain_id_hex,
                'explorer_url' => $chain->explorer_url,
                'native_token' => $chain->native_token,
                'icon_url'     => $chain->icon_url,
            ];
        }

        return $result;
    }
}
