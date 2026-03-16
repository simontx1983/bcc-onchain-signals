<?php
/**
 * Wallet Connect & Verify
 *
 * Handles the full wallet lifecycle:
 *  1. Generate a nonce/challenge message
 *  2. User signs with wallet (MetaMask / Keplr / Phantom)
 *  3. Server verifies signature → inserts wallet_link row → marks verified
 *  4. CRUD: list, set-primary, disconnect
 *
 * Signature verification is chain-type specific:
 *  - EVM:    ecrecover via elliptic curve (uses Ethereum personal_sign)
 *  - Cosmos: amino signature verification
 *  - Solana: ed25519 signature verification
 *
 * @package BCC_Onchain_Signals
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Wallet_Connect {

    /**
     * Challenge message prefix — appended with a nonce.
     * Note: use actual newlines, not escaped \n (sanitize_text_field would strip them).
     */
    const CHALLENGE_PREFIX = "Sign this message to verify your wallet on Blue Collar Crypto. Nonce: ";

    /**
     * Challenge TTL in seconds (5 minutes).
     */
    const CHALLENGE_TTL = 300;

    /**
     * Boot hooks.
     */
    public static function init(): void {
        // AJAX endpoints (logged-in users only)
        add_action('wp_ajax_bcc_wallet_challenge',    [__CLASS__, 'ajax_challenge']);
        add_action('wp_ajax_bcc_wallet_verify',       [__CLASS__, 'ajax_verify']);
        add_action('wp_ajax_bcc_wallet_disconnect',   [__CLASS__, 'ajax_disconnect']);
        add_action('wp_ajax_bcc_wallet_set_primary',  [__CLASS__, 'ajax_set_primary']);
        add_action('wp_ajax_bcc_wallet_list',         [__CLASS__, 'ajax_list']);

        // REST endpoints
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);

        // Enqueue frontend assets on PeepSo pages
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    // ── AJAX: Generate Challenge ─────────────────────────────────────────────

    /**
     * Generate a challenge nonce for the user to sign.
     * POST: chain_slug, wallet_address
     */
    public static function ajax_challenge(): void {
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

        // Resolve chain
        $chain_id = bcc_onchain_resolve_chain_id($chain_slug);
        if (!$chain_id) {
            wp_send_json_error(['message' => 'Unsupported chain.'], 400);
        }

        // Generate a unique nonce
        $nonce = wp_generate_password(32, false);
        $message = self::CHALLENGE_PREFIX . $nonce;

        // Store challenge in user meta (more reliable than transients on local dev)
        $challenge_data = [
            'nonce'          => $nonce,
            'message'        => $message,
            'chain_slug'     => $chain_slug,
            'chain_id'       => $chain_id,
            'wallet_address' => $wallet_address,
            'expires_at'     => time() + self::CHALLENGE_TTL,
        ];
        update_user_meta($user_id, self::challenge_key($user_id, $wallet_address), $challenge_data);

        wp_send_json_success([
            'message' => $message,
            'nonce'   => $nonce,
        ]);
    }

    // ── AJAX: Verify Signature ───────────────────────────────────────────────

    /**
     * Verify a signed challenge and link the wallet.
     * POST: wallet_address, signature, post_id, wallet_type (optional)
     */
    public static function ajax_verify(): void {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'Not logged in.'], 401);
        }

        $wallet_address = sanitize_text_field($_POST['wallet_address'] ?? '');
        $post_id        = (int) ($_POST['post_id'] ?? 0);
        $wallet_type    = sanitize_text_field($_POST['wallet_type'] ?? 'user');
        $label          = sanitize_text_field($_POST['label'] ?? '');

        // Signature may be a hex string (EVM), base58 (Solana), or a JSON
        // object (Cosmos: {"signature":"…","pub_key":{…}}).
        // sanitize_text_field() would destroy the JSON, so we use
        // wp_unslash + manual validation instead.
        $raw_sig = wp_unslash($_POST['signature'] ?? '');
        // Strip any HTML tags for safety, but preserve JSON structure
        $signature = wp_strip_all_tags($raw_sig);

        if (!$wallet_address || !$signature) {
            wp_send_json_error(['message' => 'Missing address or signature.'], 400);
        }

        // Retrieve stored challenge from user meta
        $meta_key  = self::challenge_key($user_id, $wallet_address);
        $challenge = get_user_meta($user_id, $meta_key, true);

        if (!$challenge || !is_array($challenge)) {
            wp_send_json_error(['message' => 'Challenge not found. Please try again.'], 400);
        }

        // Check expiry
        if (time() > ($challenge['expires_at'] ?? 0)) {
            delete_user_meta($user_id, $meta_key);
            wp_send_json_error(['message' => 'Challenge expired. Please try again.'], 400);
        }

        // Get chain info
        $chain = bcc_onchain_get_chain_by_id((int) $challenge['chain_id']);
        if (!$chain) {
            wp_send_json_error(['message' => 'Chain not found.'], 400);
        }

        // Check if already linked before expensive signature verification.
        // Safe: user is already authenticated via check_ajax_referer + get_current_user_id.
        if (bcc_onchain_wallet_exists($user_id, (int) $chain->id, $wallet_address)) {
            wp_send_json_error(['message' => 'This wallet is already linked to your account.'], 409);
        }

        // Verify the signature based on chain type
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BCC Wallet Connect: Verifying chain_type=' . $chain->chain_type
                . ' address=' . $wallet_address
                . ' sig_length=' . strlen($signature)
                . ' sig_starts=' . substr($signature, 0, 50));
        }

        $valid = self::verify_signature(
            $chain->chain_type,
            $wallet_address,
            $challenge['message'],
            $signature
        );

        if (!$valid) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BCC Wallet Connect: Verification FAILED for chain_type=' . $chain->chain_type);
            }
            wp_send_json_error(['message' => 'Signature verification failed.'], 403);
        }

        // Clean up challenge
        delete_user_meta($user_id, $meta_key);

        // Insert the wallet link
        $wallet_link_id = bcc_onchain_insert_wallet([
            'user_id'        => $user_id,
            'post_id'        => $post_id,
            'wallet_address' => $wallet_address,
            'chain_id'       => (int) $chain->id,
            'wallet_type'    => $wallet_type,
            'label'          => $label,
        ]);

        if (!$wallet_link_id) {
            wp_send_json_error(['message' => 'Failed to save wallet.'], 500);
        }

        // Mark as verified
        bcc_onchain_verify_wallet($wallet_link_id);

        // If this is the user's first wallet on this chain, set as primary
        $user_wallets = bcc_onchain_get_user_wallets($user_id);
        $chain_wallets = array_filter($user_wallets, function ($w) use ($chain) {
            return (int) $w->chain_id === (int) $chain->id;
        });
        if (count($chain_wallets) <= 1) {
            bcc_onchain_set_primary_wallet($wallet_link_id, $user_id);
        }

        // Fire action for other plugins (trust engine, on-chain signals, etc.)
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

    public static function ajax_disconnect(): void {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id        = get_current_user_id();
        $wallet_link_id = (int) ($_POST['wallet_link_id'] ?? 0);

        if (!$user_id || !$wallet_link_id) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $deleted = bcc_onchain_delete_wallet($wallet_link_id, $user_id);

        if (!$deleted) {
            wp_send_json_error(['message' => 'Wallet not found or not yours.'], 404);
        }

        wp_send_json_success(['deleted' => $wallet_link_id]);
    }

    // ── AJAX: Set Primary ────────────────────────────────────────────────────

    public static function ajax_set_primary(): void {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id        = get_current_user_id();
        $wallet_link_id = (int) ($_POST['wallet_link_id'] ?? 0);

        if (!$user_id || !$wallet_link_id) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $result = bcc_onchain_set_primary_wallet($wallet_link_id, $user_id);

        if (!$result) {
            wp_send_json_error(['message' => 'Wallet not found or not yours.'], 404);
        }

        wp_send_json_success(['primary' => $wallet_link_id]);
    }

    // ── AJAX: List Wallets ───────────────────────────────────────────────────

    public static function ajax_list(): void {
        check_ajax_referer('bcc_wallet_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'Not logged in.'], 401);
        }

        $wallets = bcc_onchain_get_user_wallets($user_id);

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

    public static function register_rest_routes(): void {
        // GET /bcc/v1/wallets — list current user's wallets
        register_rest_route('bcc/v1', '/wallets', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_list_wallets'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        // GET /bcc/v1/wallets/project/{post_id} — list wallets for a project page
        register_rest_route('bcc/v1', '/wallets/project/(?P<post_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_project_wallets'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        // GET /bcc/v1/chains — list active chains
        register_rest_route('bcc/v1', '/chains', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_list_chains'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function rest_list_wallets(WP_REST_Request $req): WP_REST_Response {
        $wallets = bcc_onchain_get_user_wallets(get_current_user_id());
        return rest_ensure_response($wallets);
    }

    public static function rest_project_wallets(WP_REST_Request $req): WP_REST_Response {
        $post_id = (int) $req->get_param('post_id');
        $wallets = bcc_onchain_get_project_wallets($post_id);
        return rest_ensure_response($wallets);
    }

    public static function rest_list_chains(WP_REST_Request $req): WP_REST_Response {
        $chains = bcc_onchain_get_active_chains();
        return rest_ensure_response($chains);
    }

    // ── Signature Verification ───────────────────────────────────────────────

    /**
     * Verify a wallet signature based on chain type.
     *
     * @param string $chain_type  evm, cosmos, solana, near
     * @param string $address     Wallet address
     * @param string $message     The original challenge message
     * @param string $signature   The signed message
     * @return bool
     */
    public static function verify_signature(string $chain_type, string $address, string $message, string $signature): bool {
        switch ($chain_type) {
            case 'evm':
                return self::verify_evm_signature($address, $message, $signature);
            case 'cosmos':
                return self::verify_cosmos_signature($address, $message, $signature);
            case 'solana':
                return self::verify_solana_signature($address, $message, $signature);
            default:
                return false;
        }
    }

    /**
     * Verify an EVM personal_sign signature.
     *
     * Uses the Ethereum signed message prefix and ecrecover via elliptic-php.
     * Note: Ethereum uses Keccak-256 (not NIST SHA3-256).
     */
    private static function verify_evm_signature(string $address, string $message, string $signature): bool {
        if (!class_exists('Elliptic\EC')) {
            error_log('BCC Wallet Connect: elliptic-php not installed. Run: composer install');
            return false;
        }

        // Ethereum signed message prefix
        $prefixed = "\x19Ethereum Signed Message:\n" . strlen($message) . $message;
        $hash     = self::keccak256($prefixed);

        // Decode hex signature (65 bytes: r[32] + s[32] + v[1])
        $sig = self::hex_decode($signature);
        if (strlen($sig) !== 65) {
            return false;
        }

        $r = substr($sig, 0, 32);
        $s = substr($sig, 32, 32);
        $v = ord($sig[64]);

        // Normalize v (27/28 → 0/1)
        if ($v >= 27) {
            $v -= 27;
        }

        try {
            $ec        = new \Elliptic\EC('secp256k1');
            $publicKey = $ec->recoverPubKey(bin2hex($hash), ['r' => bin2hex($r), 's' => bin2hex($s)], $v);

            // Ethereum address = last 20 bytes of keccak256(uncompressed_pubkey_without_prefix)
            $pubHex    = substr($publicKey->encode('hex'), 2); // strip 04 prefix
            $recovered = '0x' . substr(bin2hex(self::keccak256(hex2bin($pubHex))), -40);

            return strtolower($recovered) === strtolower($address);
        } catch (\Exception $e) {
            error_log('BCC Wallet Connect: EVM verify error — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify a Cosmos amino signature (Keplr signAmino).
     *
     * The frontend sends a JSON string containing:
     *   { "signature": "<base64>", "pub_key": { "type": "...", "value": "<base64>" } }
     *
     * Verification:
     * 1. Reconstruct the sign doc that Keplr signed
     * 2. SHA-256 hash it (Cosmos uses SHA-256 for signing)
     * 3. Verify the secp256k1 signature against the public key
     * 4. Derive the bech32 address from the pubkey and compare
     */
    private static function verify_cosmos_signature(string $address, string $message, string $signature): bool {
        if (!class_exists('Elliptic\EC')) {
            error_log('BCC Wallet Connect: elliptic-php not installed. Run: composer install');
            return false;
        }

        // Decode the JSON payload from the frontend
        $payload = json_decode($signature, true);
        if (!$payload || empty($payload['signature']) || empty($payload['pub_key'])) {
            error_log('BCC Wallet Connect: Cosmos payload parse failed. json_last_error=' . json_last_error()
                . ' sig_length=' . strlen($signature)
                . ' first_100=' . substr($signature, 0, 100));
            return false;
        }

        $sig_bytes = base64_decode($payload['signature']);
        $pub_value = $payload['pub_key']['value'] ?? '';
        $pub_bytes = base64_decode($pub_value);

        if (strlen($sig_bytes) !== 64 || strlen($pub_bytes) !== 33) {
            return false;
        }

        // Reconstruct the amino sign doc that Keplr signed.
        // CRITICAL: Keplr sorts ALL keys alphabetically before signing
        // (Cosmos canonical JSON). The key order must match exactly or
        // the SHA-256 hash will differ and verification fails.
        $sign_doc = json_encode([
            'account_number' => '0',
            'chain_id'       => '',
            'fee'            => ['amount' => [], 'gas' => '0'],
            'memo'           => '',
            'msgs'           => [[
                'type'  => 'sign/MsgSignData',
                'value' => [
                    'data'   => base64_encode($message),
                    'signer' => $address,
                ],
            ]],
            'sequence'       => '0',
        ], JSON_UNESCAPED_SLASHES);

        // Cosmos signs the SHA-256 hash of the canonical JSON
        $hash = hash('sha256', $sign_doc, true);

        try {
            $ec  = new \Elliptic\EC('secp256k1');
            $key = $ec->keyFromPublic(bin2hex($pub_bytes), 'hex');

            $r = substr($sig_bytes, 0, 32);
            $s = substr($sig_bytes, 32, 32);

            $valid = $key->verify(bin2hex($hash), ['r' => bin2hex($r), 's' => bin2hex($s)]);

            if (!$valid) {
                return false;
            }

            // Derive bech32 address from pubkey and compare
            // address = bech32(ripemd160(sha256(compressed_pubkey)))
            $sha  = hash('sha256', $pub_bytes, true);
            $ripe = hash('ripemd160', $sha, true);
            $derived_address = self::bech32_encode(self::get_bech32_prefix($address), $ripe);

            return strtolower($derived_address) === strtolower($address);
        } catch (\Exception $e) {
            error_log('BCC Wallet Connect: Cosmos verify error — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify a Solana ed25519 signature.
     *
     * Solana uses ed25519 — the signature is 64 bytes, signed over the raw message.
     */
    private static function verify_solana_signature(string $address, string $message, string $signature): bool {
        // Requires sodium extension (available in PHP 7.2+)
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            error_log('BCC Wallet Connect: sodium extension required for Solana verification.');
            return false;
        }

        try {
            $sig_bytes = self::base58_decode($signature);
            $pub_bytes = self::base58_decode($address);
            $msg_bytes = $message; // Solana signs raw UTF-8 message bytes

            if (strlen($sig_bytes) !== 64 || strlen($pub_bytes) !== 32) {
                return false;
            }

            return sodium_crypto_sign_verify_detached($sig_bytes, $msg_bytes, $pub_bytes);
        } catch (\Exception $e) {
            error_log('BCC Wallet Connect: Solana verify error — ' . $e->getMessage());
            return false;
        }
    }

    // ── Frontend Assets ──────────────────────────────────────────────────────

    public static function enqueue_assets(): void {
        // Only on PeepSo pages and when user is logged in
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
            'chains'  => self::get_chains_for_js(),
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

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build the transient key for a challenge nonce.
     */
    private static function challenge_key(int $user_id, string $address): string {
        return 'bcc_wallet_challenge_' . $user_id . '_' . md5(strtolower($address));
    }

    /**
     * Decode a hex string (with or without 0x prefix).
     */
    private static function hex_decode(string $hex): string {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }

        $bin = hex2bin($hex);

        return $bin === false ? '' : $bin;
    }

    /**
     * Base58 decode (for Solana addresses and signatures).
     */
    private static function base58_decode(string $input): string {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base     = strlen($alphabet);

        $num = gmp_init(0);
        for ($i = 0; $i < strlen($input); $i++) {
            $pos = strpos($alphabet, $input[$i]);
            if ($pos === false) {
                return '';
            }
            $num = gmp_add(gmp_mul($num, $base), $pos);
        }

        $hex = gmp_strval($num, 16);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }

        // Preserve leading zeros
        $leading = 0;
        for ($i = 0; $i < strlen($input); $i++) {
            if ($input[$i] !== '1') break;
            $leading++;
        }

        return str_repeat("\x00", $leading) . hex2bin($hex);
    }

    /**
     * Keccak-256 hash (Ethereum uses Keccak, not NIST SHA3).
     * Requires PHP 8.1+ hash('keccak256') or the sha3 polyfill.
     */
    private static function keccak256(string $data): string {
        // PHP 8.1+ has native keccak support
        if (in_array('keccak256', hash_algos(), true)) {
            return hash('keccak256', $data, true);
        }

        // kornrunner/keccak (installed via Composer)
        if (class_exists('kornrunner\\Keccak')) {
            return hex2bin(\kornrunner\Keccak::hash($data, 256));
        }

        error_log('BCC Wallet Connect: No Keccak-256 implementation. Run: composer install');
        return '';
    }

    /**
     * Bech32 encode (for Cosmos address derivation).
     *
     * @param string $hrp   Human readable part (e.g. 'cosmos', 'osmo')
     * @param string $data  Raw 20-byte hash
     * @return string       Bech32-encoded address
     */
    private static function bech32_encode(string $hrp, string $data): string {
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

        // Convert 8-bit data to 5-bit groups
        $values = self::convert_bits(array_values(unpack('C*', $data)), 8, 5, true);
        if ($values === null) {
            return '';
        }

        // Compute checksum
        $polymod = self::bech32_polymod(array_merge(
            self::bech32_hrp_expand($hrp),
            $values,
            [0, 0, 0, 0, 0, 0]
        )) ^ 1;

        $checksum = [];
        for ($i = 0; $i < 6; $i++) {
            $checksum[] = ($polymod >> (5 * (5 - $i))) & 31;
        }

        $result = $hrp . '1';
        foreach (array_merge($values, $checksum) as $v) {
            $result .= $charset[$v];
        }

        return $result;
    }

    private static function bech32_polymod(array $values): int {
        $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $v) {
            $b = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                $chk ^= (($b >> $i) & 1) ? $gen[$i] : 0;
            }
        }
        return $chk;
    }

    private static function bech32_hrp_expand(string $hrp): array {
        $expand = [];
        for ($i = 0; $i < strlen($hrp); $i++) {
            $expand[] = ord($hrp[$i]) >> 5;
        }
        $expand[] = 0;
        for ($i = 0; $i < strlen($hrp); $i++) {
            $expand[] = ord($hrp[$i]) & 31;
        }
        return $expand;
    }

    /**
     * Convert between bit groups (e.g., 8-bit to 5-bit for bech32).
     */
    private static function convert_bits(array $data, int $fromBits, int $toBits, bool $pad = true): ?array {
        $acc    = 0;
        $bits   = 0;
        $result = [];
        $maxv   = (1 << $toBits) - 1;

        foreach ($data as $value) {
            $acc = ($acc << $fromBits) | $value;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $result[] = ($acc >> $bits) & $maxv;
            }
        }

        if ($pad && $bits > 0) {
            $result[] = ($acc << ($toBits - $bits)) & $maxv;
        }

        return $result;
    }

    /**
     * Extract the bech32 prefix (HRP) from a Cosmos address.
     * e.g., 'cosmos1abc...' → 'cosmos', 'osmo1xyz...' → 'osmo'
     */
    private static function get_bech32_prefix(string $address): string {
        $pos = strrpos($address, '1');
        return $pos !== false ? substr($address, 0, $pos) : 'cosmos';
    }

    /**
     * Get active chains formatted for the JS frontend.
     */
    private static function get_chains_for_js(): array {
        $chains = bcc_onchain_get_active_chains();
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

// Boot
BCC_Wallet_Connect::init();
