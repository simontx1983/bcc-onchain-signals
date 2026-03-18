<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Onchain\Factories\FetcherFactory instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Fetcher_Factory extends \BCC\Onchain\Factories\FetcherFactory {}
