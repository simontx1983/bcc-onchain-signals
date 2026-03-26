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
    return \BCC\Onchain\Repositories\WalletRepository::insert($data);
}

function bcc_onchain_verify_wallet(int $wallet_link_id): bool {
    return \BCC\Onchain\Repositories\WalletRepository::verify($wallet_link_id);
}

function bcc_onchain_delete_wallet(int $wallet_link_id, int $user_id): bool {
    return \BCC\Onchain\Repositories\WalletRepository::delete($wallet_link_id, $user_id);
}

function bcc_onchain_set_primary_wallet(int $wallet_link_id, int $user_id): bool {
    return \BCC\Onchain\Repositories\WalletRepository::setPrimary($wallet_link_id, $user_id);
}

function bcc_onchain_get_user_wallets(int $user_id, ?string $wallet_type = null, bool $verified_only = false): array {
    return \BCC\Onchain\Repositories\WalletRepository::getForUser($user_id, $wallet_type, $verified_only);
}

function bcc_onchain_get_project_wallets(int $post_id, ?string $wallet_type = null): array {
    return \BCC\Onchain\Repositories\WalletRepository::getForProject($post_id, $wallet_type);
}

function bcc_onchain_wallet_exists(int $user_id, int $chain_id, string $wallet_address): bool {
    return \BCC\Onchain\Repositories\WalletRepository::exists($user_id, $chain_id, $wallet_address);
}