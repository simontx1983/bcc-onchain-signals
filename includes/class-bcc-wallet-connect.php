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

// Boot hooks — preserved for backward compat.
BCC_Wallet_Connect::init();
