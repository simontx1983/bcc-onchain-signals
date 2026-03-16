<?php
/**
 * Wallet Links Schema
 *
 * Links wallet addresses to users and their project pages.
 * Supports multiple wallets per user with typed roles
 * (user, treasury, multisig, validator, contract_deployer).
 *
 * @package BCC_Onchain_Signals
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table name helper.
 */
function bcc_onchain_wallet_links_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'bcc_wallet_links';
}

/**
 * Create the wallet links table.
 */
function bcc_onchain_create_wallet_links_table(): void {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_wallet_links_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        post_id BIGINT UNSIGNED NOT NULL,
        wallet_address VARCHAR(128) NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        wallet_type VARCHAR(20) NOT NULL DEFAULT 'user',
        label VARCHAR(100) DEFAULT NULL,
        verified_at DATETIME DEFAULT NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY post_id (post_id),
        KEY chain_id (chain_id),
        KEY wallet_address (wallet_address),
        KEY wallet_type (wallet_type),
        UNIQUE KEY user_chain_wallet (user_id, chain_id, wallet_address)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// ── CRUD Operations ──────────────────────────────────────────────────────────

/**
 * Insert a new wallet link. Returns the inserted ID or 0 on failure.
 *
 * @param array $data {
 *     @type int    $user_id
 *     @type int    $post_id
 *     @type string $wallet_address
 *     @type int    $chain_id
 *     @type string $wallet_type     Optional. Default 'user'.
 *     @type string $label           Optional.
 * }
 * @return int Inserted row ID, or 0 on failure.
 */
function bcc_onchain_insert_wallet(array $data): int {
    global $wpdb;
    $table = bcc_onchain_wallet_links_table();

    $inserted = $wpdb->insert($table, [
        'user_id'        => (int) $data['user_id'],
        'post_id'        => (int) $data['post_id'],
        'wallet_address' => sanitize_text_field($data['wallet_address']),
        'chain_id'       => (int) $data['chain_id'],
        'wallet_type'    => sanitize_text_field($data['wallet_type'] ?? 'user'),
        'label'          => isset($data['label']) ? sanitize_text_field($data['label']) : null,
    ], ['%d', '%d', '%s', '%d', '%s', '%s']);

    return $inserted ? (int) $wpdb->insert_id : 0;
}

/**
 * Mark a wallet link as verified (signature confirmed).
 *
 * @param int $wallet_link_id
 * @return bool
 */
function bcc_onchain_verify_wallet(int $wallet_link_id): bool {
    global $wpdb;
    $table = bcc_onchain_wallet_links_table();

    return (bool) $wpdb->update(
        $table,
        ['verified_at' => current_time('mysql', true)],
        ['id' => $wallet_link_id],
        ['%s'],
        ['%d']
    );
}

/**
 * Delete a wallet link.
 *
 * @param int $wallet_link_id
 * @param int $user_id Owner check — prevents deleting another user's wallet.
 * @return bool
 */
function bcc_onchain_delete_wallet(int $wallet_link_id, int $user_id): bool {
    global $wpdb;
    $table = bcc_onchain_wallet_links_table();

    return (bool) $wpdb->delete(
        $table,
        ['id' => $wallet_link_id, 'user_id' => $user_id],
        ['%d', '%d']
    );
}

/**
 * Set a wallet as the primary wallet for its user+chain combination.
 * Clears is_primary on all other wallets for that user+chain first.
 *
 * @param int $wallet_link_id
 * @param int $user_id
 * @return bool
 */
function bcc_onchain_set_primary_wallet(int $wallet_link_id, int $user_id): bool {
    global $wpdb;
    $table = bcc_onchain_wallet_links_table();

    // Get the chain_id of the wallet being set as primary
    $chain_id = $wpdb->get_var($wpdb->prepare(
        "SELECT chain_id FROM {$table} WHERE id = %d AND user_id = %d",
        $wallet_link_id, $user_id
    ));

    if (!$chain_id) {
        return false;
    }

    // Clear existing primary for this user+chain
    $wpdb->update(
        $table,
        ['is_primary' => 0],
        ['user_id' => $user_id, 'chain_id' => $chain_id],
        ['%d'],
        ['%d', '%d']
    );

    // Set new primary
    return (bool) $wpdb->update(
        $table,
        ['is_primary' => 1],
        ['id' => $wallet_link_id, 'user_id' => $user_id],
        ['%d'],
        ['%d', '%d']
    );
}

// ── Query Operations ─────────────────────────────────────────────────────────

/**
 * Get all wallets for a user, optionally filtered.
 *
 * @param int         $user_id
 * @param string|null $wallet_type Filter by type.
 * @param bool        $verified_only Only return verified wallets.
 * @return array
 */
function bcc_onchain_get_user_wallets(int $user_id, ?string $wallet_type = null, bool $verified_only = false): array {
    global $wpdb;
    $table  = bcc_onchain_wallet_links_table();
    $chains = bcc_onchain_chains_table();

    $where = ['w.user_id = %d'];
    $args  = [$user_id];

    if ($wallet_type) {
        $where[] = 'w.wallet_type = %s';
        $args[]  = $wallet_type;
    }

    if ($verified_only) {
        $where[] = 'w.verified_at IS NOT NULL';
    }

    $where_sql = implode(' AND ', $where);

    return $wpdb->get_results($wpdb->prepare(
        "SELECT w.*, c.slug AS chain_slug, c.name AS chain_name, c.chain_type, c.explorer_url
         FROM {$table} w
         JOIN {$chains} c ON c.id = w.chain_id
         WHERE {$where_sql}
         ORDER BY w.is_primary DESC, w.created_at ASC",
        ...$args
    ));
}

/**
 * Get all wallets for a project page (shadow CPT post_id).
 *
 * @param int         $post_id
 * @param string|null $wallet_type Filter by type.
 * @return array
 */
function bcc_onchain_get_project_wallets(int $post_id, ?string $wallet_type = null): array {
    global $wpdb;
    $table  = bcc_onchain_wallet_links_table();
    $chains = bcc_onchain_chains_table();

    $where = ['w.post_id = %d'];
    $args  = [$post_id];

    if ($wallet_type) {
        $where[] = 'w.wallet_type = %s';
        $args[]  = $wallet_type;
    }

    $where_sql = implode(' AND ', $where);

    return $wpdb->get_results($wpdb->prepare(
        "SELECT w.*, c.slug AS chain_slug, c.name AS chain_name, c.chain_type, c.explorer_url
         FROM {$table} w
         JOIN {$chains} c ON c.id = w.chain_id
         WHERE {$where_sql}
         ORDER BY w.is_primary DESC, w.wallet_type ASC, w.created_at ASC",
        ...$args
    ));
}

/**
 * Get a single wallet link by ID.
 *
 * @param int $wallet_link_id
 * @return object|null
 */
function bcc_onchain_get_wallet(int $wallet_link_id): ?object {
    global $wpdb;
    $table  = bcc_onchain_wallet_links_table();
    $chains = bcc_onchain_chains_table();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT w.*, c.slug AS chain_slug, c.name AS chain_name, c.chain_type, c.explorer_url
         FROM {$table} w
         JOIN {$chains} c ON c.id = w.chain_id
         WHERE w.id = %d",
        $wallet_link_id
    ));
}

/**
 * Check if a wallet address is already linked for a user on a specific chain.
 *
 * @param int    $user_id
 * @param int    $chain_id
 * @param string $wallet_address
 * @return bool
 */
function bcc_onchain_wallet_exists(int $user_id, int $chain_id, string $wallet_address): bool {
    global $wpdb;
    $table = bcc_onchain_wallet_links_table();

    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1) FROM {$table} WHERE user_id = %d AND chain_id = %d AND wallet_address = %s",
        $user_id, $chain_id, $wallet_address
    ));
}