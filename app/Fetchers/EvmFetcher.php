<?php

namespace BCC\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Contracts\FetcherInterface;

/**
 * EVM Chain Fetcher (Stub)
 *
 * Will fetch NFT collections, DAO stats, and deployed contracts
 * via Alchemy, Etherscan, Reservoir, and Tally APIs.
 */
class EvmFetcher implements FetcherInterface
{
    private object $chain;

    public function __construct(object $chain)
    {
        $this->chain = $chain;
    }

    public function get_chain(): object
    {
        return $this->chain;
    }

    public function supports_feature(string $feature): bool
    {
        return false;
    }

    public function fetch_validator(string $address): array
    {
        return [];
    }

    public function fetch_collections(string $address): array
    {
        // TODO: Implement via Alchemy getNFTsForOwner / Reservoir
        return [];
    }

    public function fetch_dao_stats(string $contract): array
    {
        // TODO: Implement via Tally API / Snapshot
        return [];
    }

    public function fetch_contracts(string $address): array
    {
        // TODO: Implement via Etherscan "contracts created" API
        return [];
    }
}
