<?php

namespace BCC\Onchain\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract for fetching NFT collection data from any blockchain.
 *
 * Every chain fetcher that supports collections must implement this
 * interface and return data in the normalized format below.
 *
 * Normalized output per collection:
 *   contract_address  — string, on-chain contract/mint authority address
 *   collection_name   — string|null, human-readable name
 *   chain_id          — int, FK to bcc_chains.id
 *   token_standard    — string|null, e.g. 'ERC-721', 'Metaplex', 'CW-721'
 *   total_supply      — int|null
 *   floor_price       — float|null, in native token
 *   floor_currency    — string|null, e.g. 'ETH', 'SOL'
 *   total_volume      — float|null, in native token
 *   unique_holders    — int|null
 *   listed_percentage — float|null
 *   royalty_percentage — float|null
 *   metadata_storage  — string|null, e.g. 'ipfs', 'arweave', 'onchain'
 */
interface CollectionFetcherInterface
{
    /**
     * Fetch NFT collections associated with a wallet address on a specific chain.
     *
     * @param string $walletAddress  The wallet address to query.
     * @param int    $chainId        The chain ID (FK to bcc_chains.id).
     * @return array[] Array of normalized collection rows. Empty array if none found.
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array;
}
