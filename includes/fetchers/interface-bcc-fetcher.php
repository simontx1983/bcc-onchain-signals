<?php
/**
 * Chain Fetcher Interface
 *
 * Contract for all chain-specific data fetchers.
 * Each driver (EVM, Cosmos, Solana) implements this interface
 * and declares which features it supports.
 *
 * @package BCC_Onchain_Signals
 * @subpackage Fetchers
 */

if (!defined('ABSPATH')) {
    exit;
}

interface BCC_Fetcher_Interface {

    /**
     * Check if this driver supports a specific feature.
     *
     * @param string $feature One of: 'validator', 'nft', 'dao', 'contracts'
     * @return bool
     */
    public function supports_feature(string $feature): bool;

    /**
     * Fetch validator data for a given operator/wallet address.
     *
     * @param string $address Validator operator address or wallet address.
     * @return array Array of validator data rows (one per chain for multi-chain validators).
     */
    public function fetch_validator(string $address): array;

    /**
     * Fetch NFT collection data for a wallet (collections created by this address).
     *
     * @param string $address Creator wallet address.
     * @return array Array of collection data rows.
     */
    public function fetch_collections(string $address): array;

    /**
     * Fetch DAO governance stats for a governance contract.
     *
     * @param string $contract Governance contract address or DAO identifier.
     * @return array DAO governance data.
     */
    public function fetch_dao_stats(string $contract): array;

    /**
     * Fetch deployed contracts for a wallet address.
     *
     * @param string $address Deployer wallet address.
     * @return array Array of contract data rows.
     */
    public function fetch_contracts(string $address): array;

    /**
     * Get the chain object this fetcher is configured for.
     *
     * @return object Chain row from wp_bcc_chains.
     */
    public function get_chain(): object;
}
