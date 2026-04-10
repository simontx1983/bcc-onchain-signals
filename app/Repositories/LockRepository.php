<?php

namespace BCC\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MySQL advisory lock operations for bcc-onchain-signals.
 */
final class LockRepository
{
    public static function acquire(string $key, int $timeout = 0): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $key, $timeout)
        ) === 1;
    }

    public static function release(string $key): void
    {
        global $wpdb;
        $wpdb->get_var(
            $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)
        );
    }
}
