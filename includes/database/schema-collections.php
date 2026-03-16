<?php
/**
 * On-Chain NFT Collections Schema
 *
 * @package BCC_Onchain_Signals
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_onchain_collections_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'bcc_onchain_collections';
}

function bcc_onchain_create_collections_table(): void {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_collections_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wallet_link_id BIGINT UNSIGNED NOT NULL,
        contract_address VARCHAR(128) NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        collection_name VARCHAR(200) DEFAULT NULL,
        token_standard VARCHAR(20) DEFAULT NULL,
        total_supply INT UNSIGNED DEFAULT NULL,
        floor_price DECIMAL(20,8) DEFAULT NULL,
        floor_currency VARCHAR(20) DEFAULT NULL,
        unique_holders INT UNSIGNED DEFAULT NULL,
        total_volume DECIMAL(20,8) DEFAULT NULL,
        listed_percentage DECIMAL(5,2) DEFAULT NULL,
        royalty_percentage DECIMAL(5,2) DEFAULT NULL,
        metadata_storage VARCHAR(30) DEFAULT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY wallet_link_id (wallet_link_id),
        KEY chain_id (chain_id),
        KEY contract_address (contract_address),
        KEY expires_at (expires_at),
        UNIQUE KEY wallet_chain_contract (wallet_link_id, chain_id, contract_address)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function bcc_onchain_get_collections_for_project(int $post_id, int $page = 1, int $per_page = 8, string $order_by = 'total_volume'): array {
    global $wpdb;
    $table   = bcc_onchain_collections_table();
    $wallets = bcc_onchain_wallet_links_table();
    $chains  = bcc_onchain_chains_table();

    $allowed_order = ['total_volume', 'floor_price', 'unique_holders', 'total_supply', 'collection_name'];
    if (!in_array($order_by, $allowed_order, true)) $order_by = 'total_volume';

    $offset = ($page - 1) * $per_page;

    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} c JOIN {$wallets} w ON w.id = c.wallet_link_id WHERE w.post_id = %d",
        $post_id
    ));

    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT c.*, ch.slug AS chain_slug, ch.name AS chain_name, ch.explorer_url, ch.native_token
         FROM {$table} c
         JOIN {$wallets} w ON w.id = c.wallet_link_id
         JOIN {$chains} ch ON ch.id = c.chain_id
         WHERE w.post_id = %d
         ORDER BY c.{$order_by} DESC
         LIMIT %d OFFSET %d",
        $post_id, $per_page, $offset
    ));

    return ['items' => $items ?: [], 'total' => $total, 'pages' => (int) ceil($total / $per_page)];
}

function bcc_onchain_get_expired_collections(int $limit = 50): array {
    global $wpdb;
    $table = bcc_onchain_collections_table();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE expires_at < NOW() ORDER BY expires_at ASC LIMIT %d", $limit
    ));
}
