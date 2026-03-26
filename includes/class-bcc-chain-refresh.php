<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Onchain\Services\ChainRefreshService instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Chain_Refresh extends \BCC\Onchain\Services\ChainRefreshService {}

// init() is now called from bcc_onchain_boot() — the single bootstrap entry point.
