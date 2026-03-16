<?php
/**
 * EVM Chain Fetcher (Stub)
 *
 * Will fetch NFT collections, DAO stats, and deployed contracts
 * via Alchemy, Etherscan, Reservoir, and Tally APIs.
 *
 * @package BCC_Onchain_Signals
 * @subpackage Fetchers
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Fetcher_EVM implements BCC_Fetcher_Interface {

    private object $chain;

    public function __construct(object $chain) {
        $this->chain = $chain;
    }

    public function get_chain(): object {
        return $this->chain;
    }

    public function supports_feature(string $feature): bool {
        // Stubs — return false until fetch methods are implemented
        return false;
    }

    public function fetch_validator(string $address): array {
        return []; // EVM chains don't have native validators (except ETH beacon, future work)
    }

    public function fetch_collections(string $address): array {
        // TODO: Implement via Alchemy getNFTsForOwner / Reservoir
        return [];
    }

    public function fetch_dao_stats(string $contract): array {
        // TODO: Implement via Tally API / Snapshot
        return [];
    }

    public function fetch_contracts(string $address): array {
        // TODO: Implement via Etherscan "contracts created" API
        return [];
    }
}
