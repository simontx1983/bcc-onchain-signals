<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Onchain\Fetchers\CosmosFetcher instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Fetcher_Cosmos extends \BCC\Onchain\Fetchers\CosmosFetcher {}
