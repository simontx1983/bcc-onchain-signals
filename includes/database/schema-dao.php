<?php
/**
 * On-Chain DAO Stats & Treasury Schema
 *
 * @package BCC_Onchain_Signals
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_onchain_dao_stats_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'bcc_onchain_dao_stats';
}

function bcc_onchain_treasury_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'bcc_onchain_treasury';
}

function bcc_onchain_create_dao_tables(): void {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // DAO Stats
    $table = bcc_onchain_dao_stats_table();
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wallet_link_id BIGINT UNSIGNED NOT NULL,
        governance_contract VARCHAR(128) NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        platform VARCHAR(30) DEFAULT NULL,
        total_proposals INT UNSIGNED DEFAULT NULL,
        passed_proposals INT UNSIGNED DEFAULT NULL,
        participation_rate DECIMAL(5,2) DEFAULT NULL,
        quorum_threshold DECIMAL(5,2) DEFAULT NULL,
        token_holders INT UNSIGNED DEFAULT NULL,
        active_voters INT UNSIGNED DEFAULT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY wallet_link_id (wallet_link_id),
        KEY chain_id (chain_id),
        KEY governance_contract (governance_contract),
        KEY expires_at (expires_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Treasury
    $table = bcc_onchain_treasury_table();
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        dao_stat_id BIGINT UNSIGNED NOT NULL,
        token_address VARCHAR(128) DEFAULT NULL,
        token_symbol VARCHAR(20) NOT NULL,
        token_amount DECIMAL(30,8) DEFAULT NULL,
        usd_value DECIMAL(20,2) DEFAULT NULL,
        percentage DECIMAL(5,2) DEFAULT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY dao_stat_id (dao_stat_id),
        KEY expires_at (expires_at)
    ) {$charset_collate};";

    dbDelta($sql);
}

function bcc_onchain_get_dao_stats_for_project(int $post_id): array {
    global $wpdb;
    $table   = bcc_onchain_dao_stats_table();
    $wallets = bcc_onchain_wallet_links_table();
    $chains  = bcc_onchain_chains_table();

    return $wpdb->get_results($wpdb->prepare(
        "SELECT d.*, c.slug AS chain_slug, c.name AS chain_name
         FROM {$table} d
         JOIN {$wallets} w ON w.id = d.wallet_link_id
         JOIN {$chains} c ON c.id = d.chain_id
         WHERE w.post_id = %d
         ORDER BY d.total_proposals DESC",
        $post_id
    )) ?: [];
}

function bcc_onchain_get_treasury_for_dao(int $dao_stat_id): array {
    global $wpdb;
    $table = bcc_onchain_treasury_table();

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE dao_stat_id = %d ORDER BY usd_value DESC",
        $dao_stat_id
    )) ?: [];
}

function bcc_onchain_get_expired_dao_stats(int $limit = 50): array {
    global $wpdb;
    $table = bcc_onchain_dao_stats_table();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE expires_at < NOW() ORDER BY expires_at ASC LIMIT %d", $limit
    ));
}
