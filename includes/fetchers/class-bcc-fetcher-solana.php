<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Onchain\Fetchers\SolanaFetcher instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Fetcher_Solana extends \BCC\Onchain\Fetchers\SolanaFetcher {}
