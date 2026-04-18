<?php
/**
 * PHPStan bootstrap for bcc-onchain-signals.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 4) . '/');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'phpstan_stub');
}

// Plugin constants (normally defined in bcc-onchain-signals.php after ABSPATH check)
if (!defined('BCC_ONCHAIN_VERSION')) {
    define('BCC_ONCHAIN_VERSION', '1.2.0');
}
if (!defined('BCC_ONCHAIN_PATH')) {
    define('BCC_ONCHAIN_PATH', __DIR__ . '/');
}
if (!defined('BCC_ONCHAIN_URL')) {
    define('BCC_ONCHAIN_URL', '/wp-content/plugins/bcc-onchain-signals/');
}
if (!defined('BCC_ONCHAIN_CACHE_HOURS')) {
    define('BCC_ONCHAIN_CACHE_HOURS', 24);
}
if (!defined('BCC_ONCHAIN_MAX_TOTAL_BONUS')) {
    define('BCC_ONCHAIN_MAX_TOTAL_BONUS', 20.0);
}
if (!defined('BCC_ONCHAIN_MAX_AGE_SCORE')) {
    define('BCC_ONCHAIN_MAX_AGE_SCORE', 3.0);
}
if (!defined('BCC_ONCHAIN_MAX_CONTRACT_SCORE')) {
    define('BCC_ONCHAIN_MAX_CONTRACT_SCORE', 2.0);
}
if (!defined('BCC_ONCHAIN_MAX_DEPTH_SCORE')) {
    define('BCC_ONCHAIN_MAX_DEPTH_SCORE', 2.0);
}

// Stub ActionScheduler if not loaded
if (!class_exists('ActionScheduler_Store')) {
    class ActionScheduler_Store {
        const STATUS_PENDING = 'pending';
        const STATUS_RUNNING = 'in-progress';
        const STATUS_COMPLETE = 'complete';
        const STATUS_CANCELED = 'canceled';
        const STATUS_FAILED = 'failed';
    }
}
