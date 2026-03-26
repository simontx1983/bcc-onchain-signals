<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Onchain\Controllers\WalletController instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Wallet_Connect extends \BCC\Onchain\Controllers\WalletController {}

// init() is now called from bcc_onchain_boot() — the single bootstrap entry point.
