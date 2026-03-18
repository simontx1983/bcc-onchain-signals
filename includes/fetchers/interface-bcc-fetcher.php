<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Onchain\Contracts\FetcherInterface instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Interfaces cannot extend each other via "class extends" — use class_alias instead.
class_alias(\BCC\Onchain\Contracts\FetcherInterface::class, 'BCC_Fetcher_Interface');
