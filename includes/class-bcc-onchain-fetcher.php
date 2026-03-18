<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Onchain\Services\SignalFetcher instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Onchain_Fetcher extends \BCC\Onchain\Services\SignalFetcher {}
