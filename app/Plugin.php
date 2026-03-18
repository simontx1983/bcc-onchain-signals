<?php

namespace BCC\Onchain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight service container for the On-Chain Signals plugin.
 *
 * Mirrors the pattern used in bcc-disputes. Currently all services
 * are static, so this is primarily an organisational entry point
 * and future-proofing for DI when services gain instance state.
 */
final class Plugin
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
