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

