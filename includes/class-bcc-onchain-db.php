<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Onchain\Repositories\SignalRepository instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Onchain_DB extends \BCC\Onchain\Repositories\SignalRepository {}
