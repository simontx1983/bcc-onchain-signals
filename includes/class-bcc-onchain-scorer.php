<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Onchain\Services\SignalScorer instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Onchain_Scorer extends \BCC\Onchain\Services\SignalScorer {}
