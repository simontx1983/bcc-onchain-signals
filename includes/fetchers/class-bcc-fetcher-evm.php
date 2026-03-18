<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Onchain\Fetchers\EvmFetcher instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Fetcher_EVM extends \BCC\Onchain\Fetchers\EvmFetcher {}
