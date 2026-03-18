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

// Register cron handlers at init — preserved for backward compat.
BCC_Chain_Refresh::init();
