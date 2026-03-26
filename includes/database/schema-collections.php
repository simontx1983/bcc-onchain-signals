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

