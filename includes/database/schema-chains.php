<?php
/**
 * Chain Registry Schema
 *
 * Normalized chain lookup table — every on-chain table references chain_id
 * instead of storing chain strings. Provides RPC, explorer, and type metadata.
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
function bcc_onchain_chains_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'bcc_chains';
}

/**
 * Create the chains table and seed default chains.
 */
function bcc_onchain_create_chains_table(): void {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_chains_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(50) NOT NULL,
        name VARCHAR(100) NOT NULL,
        chain_type VARCHAR(20) NOT NULL,
        chain_id_hex VARCHAR(20) DEFAULT NULL,
        rpc_url VARCHAR(500) DEFAULT NULL,
        rest_url VARCHAR(500) DEFAULT NULL,
        explorer_url VARCHAR(500) DEFAULT NULL,
        native_token VARCHAR(20) DEFAULT NULL,
        icon_url VARCHAR(500) DEFAULT NULL,
        is_testnet TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug),
        KEY chain_type (chain_type),
        KEY is_active (is_active)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Seed default chains (idempotent via INSERT IGNORE on unique slug)
    $defaults = [
        // EVM chains
        [
            'slug'         => 'ethereum',
            'name'         => 'Ethereum',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0x1',
            'rpc_url'      => 'https://eth-mainnet.g.alchemy.com/v2/',
            'explorer_url' => 'https://etherscan.io',
            'native_token' => 'ETH',
        ],
        [
            'slug'         => 'polygon',
            'name'         => 'Polygon',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0x89',
            'rpc_url'      => 'https://polygon-mainnet.g.alchemy.com/v2/',
            'explorer_url' => 'https://polygonscan.com',
            'native_token' => 'MATIC',
        ],
        [
            'slug'         => 'arbitrum',
            'name'         => 'Arbitrum One',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0xa4b1',
            'rpc_url'      => 'https://arb-mainnet.g.alchemy.com/v2/',
            'explorer_url' => 'https://arbiscan.io',
            'native_token' => 'ETH',
        ],
        [
            'slug'         => 'optimism',
            'name'         => 'Optimism',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0xa',
            'rpc_url'      => 'https://opt-mainnet.g.alchemy.com/v2/',
            'explorer_url' => 'https://optimistic.etherscan.io',
            'native_token' => 'ETH',
        ],
        [
            'slug'         => 'base',
            'name'         => 'Base',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0x2105',
            'rpc_url'      => 'https://base-mainnet.g.alchemy.com/v2/',
            'explorer_url' => 'https://basescan.org',
            'native_token' => 'ETH',
        ],
        [
            'slug'         => 'avalanche',
            'name'         => 'Avalanche C-Chain',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0xa86a',
            'rpc_url'      => 'https://api.avax.network/ext/bc/C/rpc',
            'explorer_url' => 'https://snowtrace.io',
            'native_token' => 'AVAX',
        ],
        [
            'slug'         => 'bsc',
            'name'         => 'BNB Smart Chain',
            'chain_type'   => 'evm',
            'chain_id_hex' => '0x38',
            'rpc_url'      => 'https://bsc-dataseed.binance.org',
            'explorer_url' => 'https://bscscan.com',
            'native_token' => 'BNB',
        ],

        // Cosmos chains
        [
            'slug'         => 'cosmos',
            'name'         => 'Cosmos Hub',
            'chain_type'   => 'cosmos',
            'rest_url'     => 'https://rest.cosmos.directory/cosmoshub',
            'rpc_url'      => 'https://rpc.cosmos.directory/cosmoshub',
            'explorer_url' => 'https://www.mintscan.io/cosmos',
            'native_token' => 'ATOM',
        ],
        [
            'slug'         => 'osmosis',
            'name'         => 'Osmosis',
            'chain_type'   => 'cosmos',
            'rest_url'     => 'https://rest.cosmos.directory/osmosis',
            'rpc_url'      => 'https://rpc.cosmos.directory/osmosis',
            'explorer_url' => 'https://www.mintscan.io/osmosis',
            'native_token' => 'OSMO',
        ],
        [
            'slug'         => 'akash',
            'name'         => 'Akash',
            'chain_type'   => 'cosmos',
            'rest_url'     => 'https://rest.cosmos.directory/akash',
            'rpc_url'      => 'https://rpc.cosmos.directory/akash',
            'explorer_url' => 'https://www.mintscan.io/akash',
            'native_token' => 'AKT',
        ],
        [
            'slug'         => 'juno',
            'name'         => 'Juno',
            'chain_type'   => 'cosmos',
            'rest_url'     => 'https://rest.cosmos.directory/juno',
            'rpc_url'      => 'https://rpc.cosmos.directory/juno',
            'explorer_url' => 'https://www.mintscan.io/juno',
            'native_token' => 'JUNO',
        ],
        [
            'slug'         => 'stargaze',
            'name'         => 'Stargaze',
            'chain_type'   => 'cosmos',
            'rest_url'     => 'https://rest.cosmos.directory/stargaze',
            'rpc_url'      => 'https://rpc.cosmos.directory/stargaze',
            'explorer_url' => 'https://www.mintscan.io/stargaze',
            'native_token' => 'STARS',
        ],

        // Solana
        [
            'slug'         => 'solana',
            'name'         => 'Solana',
            'chain_type'   => 'solana',
            'rpc_url'      => 'https://api.mainnet-beta.solana.com',
            'explorer_url' => 'https://solscan.io',
            'native_token' => 'SOL',
        ],

        // NEAR
        [
            'slug'         => 'near',
            'name'         => 'NEAR Protocol',
            'chain_type'   => 'near',
            'rpc_url'      => 'https://rpc.mainnet.near.org',
            'explorer_url' => 'https://nearblocks.io',
            'native_token' => 'NEAR',
        ],
    ];

    foreach ($defaults as $chain) {
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (slug, name, chain_type, chain_id_hex, rpc_url, rest_url, explorer_url, native_token, created_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %s, NOW())",
            $chain['slug'],
            $chain['name'],
            $chain['chain_type'],
            $chain['chain_id_hex'] ?? null,
            $chain['rpc_url'] ?? null,
            $chain['rest_url'] ?? null,
            $chain['explorer_url'] ?? null,
            $chain['native_token'] ?? null
        ));
    }
}

/**
 * Get a chain row by slug.
 *
 * @param string $slug Chain slug (e.g. 'ethereum', 'cosmos').
 * @return object|null
 */
function bcc_onchain_get_chain(string $slug): ?object {
    global $wpdb;
    $table = bcc_onchain_chains_table();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE slug = %s AND is_active = 1 LIMIT 1",
        $slug
    ));
}

/**
 * Get a chain row by ID.
 *
 * @param int $chain_id
 * @return object|null
 */
function bcc_onchain_get_chain_by_id(int $chain_id): ?object {
    global $wpdb;
    $table = bcc_onchain_chains_table();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
        $chain_id
    ));
}

/**
 * Get all active chains, optionally filtered by type.
 *
 * @param string|null $chain_type Filter by type (evm, cosmos, solana, near).
 * @return array
 */
function bcc_onchain_get_active_chains(?string $chain_type = null): array {
    global $wpdb;
    $table = bcc_onchain_chains_table();

    if ($chain_type) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE is_active = 1 AND chain_type = %s ORDER BY name ASC",
            $chain_type
        ));
    }

    return $wpdb->get_results(
        "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY chain_type ASC, name ASC"
    );
}

/**
 * Resolve a chain slug to its ID. Returns null if not found.
 *
 * @param string $slug
 * @return int|null
 */
function bcc_onchain_resolve_chain_id(string $slug): ?int {
    $chain = bcc_onchain_get_chain($slug);
    return $chain ? (int) $chain->id : null;
}