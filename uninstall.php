<?php
/**
 * BCC On-Chain Signals – Uninstall handler.
 *
 * Runs when the plugin is deleted via the WordPress admin.
 * Drops all custom tables and cleans up options/cron hooks/transients.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop custom tables.
$prefix = $wpdb->prefix . 'bcc_';
$tables = [
    $prefix . 'onchain_signals',
    $prefix . 'onchain_validators',
    $prefix . 'onchain_collections',
    $prefix . 'onchain_claims',
    $prefix . 'wallet_links',
    $prefix . 'chains',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Clean up options.
$options = [
    'bcc_onchain_version',
    'bcc_onchain_pending_bonus',
    'bcc_onchain_indexer_stats',
    'bcc_onchain_enrichment_stats',
    'bcc_onchain_etherscan_key',
];

foreach ($options as $option) {
    delete_option($option);
}

// Clean up cron hooks.
$cron_hooks = [
    'bcc_onchain_daily_refresh',
    'bcc_onchain_refresh_page',
    'bcc_onchain_retry_bonus',
    'bcc_refresh_validators',
    'bcc_refresh_collections',
    'bcc_index_validators',
    'bcc_index_collections',
    'bcc_onchain_refresh_batch',
    'bcc_onchain_seed_wallet',
];

foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// Clean up transients — use range scans instead of LIKE wildcards.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE (option_name >= '_transient_bcc_onchain_'         AND option_name < '_transient_bcc_onchain_~')
        OR (option_name >= '_transient_timeout_bcc_onchain_' AND option_name < '_transient_timeout_bcc_onchain_~')
        OR (option_name >= '_transient_bcc_active_chains'    AND option_name < '_transient_bcc_active_chains~')
        OR (option_name >= '_transient_timeout_bcc_active_chains' AND option_name < '_transient_timeout_bcc_active_chains~')
        OR (option_name >= '_transient_bcc_signals_'         AND option_name < '_transient_bcc_signals_~')
        OR (option_name >= '_transient_timeout_bcc_signals_' AND option_name < '_transient_timeout_bcc_signals_~')"
);
