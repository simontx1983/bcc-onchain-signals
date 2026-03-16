<?php
/**
 * On-Chain Contracts Schema
 *
 * @package BCC_Onchain_Signals
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_onchain_contracts_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'bcc_onchain_contracts';
}

function bcc_onchain_create_contracts_table(): void {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_contracts_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wallet_link_id BIGINT UNSIGNED NOT NULL,
        contract_address VARCHAR(128) NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        contract_name VARCHAR(200) DEFAULT NULL,
        contract_type VARCHAR(50) DEFAULT NULL,
        is_verified TINYINT(1) DEFAULT 0,
        audit_provider VARCHAR(100) DEFAULT NULL,
        bug_bounty_platform VARCHAR(100) DEFAULT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY wallet_link_id (wallet_link_id),
        KEY chain_id (chain_id),
        KEY contract_address (contract_address),
        KEY expires_at (expires_at),
        KEY contract_type (contract_type),
        UNIQUE KEY wallet_chain_contract (wallet_link_id, chain_id, contract_address)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function bcc_onchain_get_contracts_for_project(int $post_id, int $page = 1, int $per_page = 8, string $order_by = 'contract_name'): array {
    global $wpdb;
    $table   = bcc_onchain_contracts_table();
    $wallets = bcc_onchain_wallet_links_table();
    $chains  = bcc_onchain_chains_table();

    $allowed_order = ['contract_name', 'contract_type', 'chain_id', 'is_verified'];
    if (!in_array($order_by, $allowed_order, true)) $order_by = 'contract_name';

    $offset = ($page - 1) * $per_page;

    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} ct JOIN {$wallets} w ON w.id = ct.wallet_link_id WHERE w.post_id = %d",
        $post_id
    ));

    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT ct.*, ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url
         FROM {$table} ct
         JOIN {$wallets} w ON w.id = ct.wallet_link_id
         JOIN {$chains} ch ON ch.id = ct.chain_id
         WHERE w.post_id = %d
         ORDER BY ct.{$order_by} ASC
         LIMIT %d OFFSET %d",
        $post_id, $per_page, $offset
    ));

    return ['items' => $items ?: [], 'total' => $total, 'pages' => (int) ceil($total / $per_page)];
}

function bcc_onchain_get_expired_contracts(int $limit = 50): array {
    global $wpdb;
    $table = bcc_onchain_contracts_table();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE expires_at < NOW() ORDER BY expires_at ASC LIMIT %d", $limit
    ));
}
