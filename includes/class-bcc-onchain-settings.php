<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Onchain\Admin\SettingsPage instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Onchain_Settings extends \BCC\Onchain\Admin\SettingsPage {}
