<?php

namespace BCC\Onchain\Factories;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Contracts\FetcherInterface;
use BCC\Onchain\Fetchers\EvmFetcher;
use BCC\Onchain\Fetchers\CosmosFetcher;
use BCC\Onchain\Fetchers\SolanaFetcher;
use BCC\Onchain\Repositories\ChainRepository;

/**
 * Chain Fetcher Factory
 *
 * Resolves chain_id → chain_type → driver class.
 */
class FetcherFactory
{
    /**
     * Map of chain_type → driver class name.
     *
     * @var array<string, string>
     */
    private static array $drivers = [
        'evm'    => EvmFetcher::class,
        'cosmos' => CosmosFetcher::class,
        'solana' => SolanaFetcher::class,
    ];

    /**
     * Create a fetcher by chain_id.
     *
     * Looks up the chain row from the DB, then delegates to make_for_chain().
     *
     * @param int $chainId FK to bcc_chains.id.
     * @return FetcherInterface
     * @throws \InvalidArgumentException If chain not found or no driver.
     */
    public static function make(int $chainId): FetcherInterface
    {
        $chain = ChainRepository::getById($chainId);

        if (!$chain) {
            throw new \InvalidArgumentException("Chain not found: {$chainId}");
        }

        return self::make_for_chain($chain);
    }

    /**
     * Create a fetcher from a chain object.
     *
     * @throws \InvalidArgumentException If no driver exists for this chain type.
     */
    public static function make_for_chain(object $chain): FetcherInterface
    {
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
     */
    public static function has_driver(string $chain_type): bool
    {
        return isset(self::$drivers[$chain_type]) && class_exists(self::$drivers[$chain_type]);
    }
}
