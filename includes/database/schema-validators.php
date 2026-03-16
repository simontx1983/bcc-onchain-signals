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

// ── CRUD ─────────────────────────────────────────────────────────────────────

/**
 * Upsert a validator row. Uses wallet_link_id + chain_id + operator_address as the logical key.
 *
 * @param array $data Validator data from fetcher.
 * @param int   $wallet_link_id
 * @param int   $ttl_seconds TTL before this row needs refreshing.
 * @return int|false Inserted/updated row ID, or false on failure.
 */
function bcc_onchain_upsert_validator(array $data, int $wallet_link_id, int $ttl_seconds = 3600) {
    global $wpdb;
    $table = bcc_onchain_validators_table();

    $expires_at = gmdate('Y-m-d H:i:s', time() + $ttl_seconds);

    // Check for existing row
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table}
         WHERE wallet_link_id = %d AND chain_id = %d AND operator_address = %s
         LIMIT 1",
        $wallet_link_id,
        (int) $data['chain_id'],
        $data['operator_address']
    ));

    $row = [
        'wallet_link_id'         => $wallet_link_id,
        'operator_address'       => $data['operator_address'],
        'chain_id'               => (int) $data['chain_id'],
        'moniker'                => $data['moniker'] ?? null,
        'status'                 => $data['status'] ?? 'unknown',
        'commission_rate'        => $data['commission_rate'] ?? null,
        'total_stake'            => $data['total_stake'] ?? null,
        'self_stake'             => $data['self_stake'] ?? null,
        'delegator_count'        => $data['delegator_count'] ?? null,
        'uptime_30d'             => $data['uptime_30d'] ?? null,
        'governance_participation' => $data['governance_participation'] ?? null,
        'jailed_count'           => $data['jailed_count'] ?? 0,
        'voting_power_rank'      => $data['voting_power_rank'] ?? null,
        'fetched_at'             => current_time('mysql', true),
        'expires_at'             => $expires_at,
    ];

    $format = ['%d', '%s', '%d', '%s', '%s', '%f', '%f', '%f', '%d', '%f', '%f', '%d', '%d', '%s', '%s'];

    if ($existing) {
        $wpdb->update($table, $row, ['id' => (int) $existing], $format, ['%d']);
        return (int) $existing;
    }

    $wpdb->insert($table, $row, $format);
    return $wpdb->insert_id ?: false;
}

/**
 * Get all on-chain validator rows for a wallet link.
 *
 * @param int $wallet_link_id
 * @return array
 */
function bcc_onchain_get_validators_for_wallet(int $wallet_link_id): array {
    global $wpdb;
    $table  = bcc_onchain_validators_table();
    $chains = bcc_onchain_chains_table();

    return $wpdb->get_results($wpdb->prepare(
        "SELECT v.*, c.slug AS chain_slug, c.name AS chain_name, c.explorer_url, c.native_token
         FROM {$table} v
         JOIN {$chains} c ON c.id = v.chain_id
         WHERE v.wallet_link_id = %d
         ORDER BY v.voting_power_rank ASC, v.total_stake DESC",
        $wallet_link_id
    ));
}

/**
 * Get all on-chain validator rows for a project (via its wallet links).
 *
 * @param int    $post_id Shadow CPT post ID.
 * @param int    $page     Page number (1-based).
 * @param int    $per_page Items per page.
 * @param string $order_by Column to sort by.
 * @return array ['items' => [], 'total' => int, 'pages' => int]
 */
function bcc_onchain_get_validators_for_project(int $post_id, int $page = 1, int $per_page = 8, string $order_by = 'total_stake'): array {
    global $wpdb;
    $table   = bcc_onchain_validators_table();
    $wallets = bcc_onchain_wallet_links_table();
    $chains  = bcc_onchain_chains_table();

    // Whitelist order_by to prevent injection
    $allowed_order = ['total_stake', 'voting_power_rank', 'commission_rate', 'delegator_count', 'uptime_30d', 'chain_name'];
    if (!in_array($order_by, $allowed_order, true)) {
        $order_by = 'total_stake';
    }

    $order_dir = ($order_by === 'voting_power_rank' || $order_by === 'commission_rate') ? 'ASC' : 'DESC';

    $offset = ($page - 1) * $per_page;

    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$table} v
         JOIN {$wallets} w ON w.id = v.wallet_link_id
         WHERE w.post_id = %d",
        $post_id
    ));

    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT v.*, c.slug AS chain_slug, c.name AS chain_name, c.explorer_url, c.native_token
         FROM {$table} v
         JOIN {$wallets} w ON w.id = v.wallet_link_id
         JOIN {$chains} c ON c.id = v.chain_id
         WHERE w.post_id = %d
         ORDER BY v.{$order_by} {$order_dir}
         LIMIT %d OFFSET %d",
        $post_id, $per_page, $offset
    ));

    return [
        'items' => $items ?: [],
        'total' => $total,
        'pages' => (int) ceil($total / $per_page),
    ];
}

/**
 * Get expired validator rows that need refreshing.
 *
 * @param int $limit Batch size.
 * @return array
 */
function bcc_onchain_get_expired_validators(int $limit = 50): array {
    global $wpdb;
    $table = bcc_onchain_validators_table();

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE expires_at < NOW() ORDER BY expires_at ASC LIMIT %d",
        $limit
    ));
}
