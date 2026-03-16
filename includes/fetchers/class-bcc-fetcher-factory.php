<?php
/**
 * Chain Fetcher Factory
 *
 * Resolves chain_id → chain_type → driver class.
 * Uses the driver pattern so each chain ecosystem handles
 * its own RPC differences internally.
 *
 * @package BCC_Onchain_Signals
 * @subpackage Fetchers
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Fetcher_Factory {

    /**
     * Map of chain_type → driver class name.
     *
     * @var array<string, string>
     */
    private static array $drivers = [
        'evm'    => 'BCC_Fetcher_EVM',
        'cosmos' => 'BCC_Fetcher_Cosmos',
        'solana' => 'BCC_Fetcher_Solana',
    ];

    /**
     * Create a fetcher for a given chain ID.
     *
     * @param int $chain_id Row ID from wp_bcc_chains.
     * @return BCC_Fetcher_Interface
     * @throws InvalidArgumentException If chain not found or no driver exists.
     */
    public static function make(int $chain_id): BCC_Fetcher_Interface {
        $chain = bcc_onchain_get_chain_by_id($chain_id);

        if (!$chain) {
            throw new \InvalidArgumentException("Chain not found: {$chain_id}");
        }

        return self::make_for_chain($chain);
    }

    /**
     * Create a fetcher for a given chain slug.
     *
     * @param string $slug Chain slug (e.g. 'cosmos', 'ethereum').
     * @return BCC_Fetcher_Interface
     * @throws InvalidArgumentException If chain not found or no driver exists.
     */
    public static function make_from_slug(string $slug): BCC_Fetcher_Interface {
        $chain = bcc_onchain_get_chain($slug);

        if (!$chain) {
            throw new \InvalidArgumentException("Chain not found: {$slug}");
        }

        return self::make_for_chain($chain);
    }

    /**
     * Create a fetcher from a chain object.
     *
     * @param object $chain Chain row from wp_bcc_chains.
     * @return BCC_Fetcher_Interface
     * @throws InvalidArgumentException If no driver exists for this chain type.
     */
    public static function make_for_chain(object $chain): BCC_Fetcher_Interface {
        $type = $chain->chain_type;

        if (!isset(self::$drivers[$type])) {
            throw new \InvalidArgumentException("No fetcher driver for chain type: {$type}");
        }

        $class = self::$drivers[$type];

        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Fetcher driver class not loaded: {$class}");
        }

        return new $class($chain);
    }

    /**
     * Check if a driver exists for a given chain type.
     *
     * @param string $chain_type
     * @return bool
     */
    public static function has_driver(string $chain_type): bool {
        return isset(self::$drivers[$chain_type]) && class_exists(self::$drivers[$chain_type]);
    }

    /**
     * Register a custom driver for a chain type.
     * Allows extending with new chain ecosystems without modifying the factory.
     *
     * @param string $chain_type
     * @param string $class_name Fully qualified class name implementing BCC_Fetcher_Interface.
     */
    public static function register_driver(string $chain_type, string $class_name): void {
        self::$drivers[$chain_type] = $class_name;
    }
}
