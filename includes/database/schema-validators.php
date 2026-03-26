<?php
/**
 * On-Chain Validators Schema
 *
 * Stores auto-fetched validator data from Cosmos LCD / beacon chain APIs.
 * Read-only from the user's perspective — updated by cron and fetchers only.
 *
 * @package BCC_Onchain_Signals
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_onchain_validators_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'bcc_onchain_validators';
}

/**
 * Create the on-chain validators table.
 */
function bcc_onchain_create_validators_table(): void {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_validators_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wallet_link_id BIGINT UNSIGNED NOT NULL,
        operator_address VARCHAR(128) NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        moniker VARCHAR(200) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'unknown',
        commission_rate DECIMAL(5,2) DEFAULT NULL,
        total_stake DECIMAL(30,8) DEFAULT NULL,
        self_stake DECIMAL(30,8) DEFAULT NULL,
        delegator_count INT UNSIGNED DEFAULT NULL,
        uptime_30d DECIMAL(5,2) DEFAULT NULL,
        governance_participation DECIMAL(5,2) DEFAULT NULL,
        jailed_count INT UNSIGNED DEFAULT 0,
        voting_power_rank INT UNSIGNED DEFAULT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY wallet_link_id (wallet_link_id),
        KEY chain_id (chain_id),
        KEY operator_address (operator_address),
        KEY expires_at (expires_at),
        KEY status (status),
        KEY voting_power_rank (voting_power_rank)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// ── CRUD — delegates to ValidatorRepository ──────────────────────────────────

function bcc_onchain_upsert_validator(array $data, int $wallet_link_id, int $ttl_seconds = 3600) {
    return \BCC\Onchain\Repositories\ValidatorRepository::upsert($data, $wallet_link_id, $ttl_seconds);
}

function bcc_onchain_get_validators_for_project(int $post_id, int $page = 1, int $per_page = 8, string $order_by = 'total_stake'): array {
    return \BCC\Onchain\Repositories\ValidatorRepository::getForProject($post_id, $page, $per_page, $order_by);
}

function bcc_onchain_get_expired_validators(int $limit = 50): array {
    return \BCC\Onchain\Repositories\ValidatorRepository::getExpired($limit);
}
