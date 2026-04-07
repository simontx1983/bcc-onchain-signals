<?php
/**
 * Plugin Name: Blue Collar Crypto – On-Chain Signals
 * Description: Enriches BCC trust scores with on-chain data: wallet age, transaction depth, and smart contract deployments (Ethereum & Solana).
 * Version: 1.6.1
 * Author: Blue Collar Labs LLC
 * Text Domain: bcc-onchain
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * Requires Plugins: bcc-core
 */

if (!defined('ABSPATH')) {
    exit;
}

// PHP 8.0+ required for match expressions and str_starts_with().
if (version_compare(PHP_VERSION, '8.0', '<')) {
    add_action('admin_notices', function () {
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html('BCC On-Chain Signals'),
            esc_html('requires PHP 8.0 or higher. You are running PHP ' . PHP_VERSION . '.')
        );
    });
    return;
}

// ── Dependency check — bcc-core must be active ──────────────────────────────
if ( ! defined( 'BCC_CORE_VERSION' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
           . '<strong>BCC On-Chain Signals:</strong> '
           . 'The <strong>BCC Core</strong> plugin must be activated first. '
           . 'Please activate BCC Core, then re-activate On-Chain Signals.'
           . '</p></div>';
    } );
    return;
}

// ── Constants ───────────────────────────────────────────────────────────────
define('BCC_ONCHAIN_VERSION', '1.9.0');
define('BCC_ONCHAIN_PATH', plugin_dir_path(__FILE__));
define('BCC_ONCHAIN_URL', plugin_dir_url(__FILE__));

// Signal score caps per category (per-wallet max: 40)
define('BCC_ONCHAIN_MAX_AGE_SCORE',      20);
define('BCC_ONCHAIN_MAX_DEPTH_SCORE',    10);
define('BCC_ONCHAIN_MAX_CONTRACT_SCORE', 10);
define('BCC_ONCHAIN_CACHE_HOURS',        24);

// Total on-chain bonus cap per page, across all wallets and chains.
define('BCC_ONCHAIN_MAX_TOTAL_BONUS',    40);

// ── Composer autoloader ─────────────────────────────────────────────────────
$bcc_onchain_autoload = BCC_ONCHAIN_PATH . 'vendor/autoload.php';
if (file_exists($bcc_onchain_autoload)) {
    require_once $bcc_onchain_autoload;
}

// ── Database schema definitions (table-creation functions) ──────────────────
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-chains.php';
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-wallets.php';
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-validators.php';
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-collections.php';
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-claims.php';

// ── Renderers ───────────────────────────────────────────────────────────────
require_once BCC_ONCHAIN_PATH . 'includes/renderers/onchain-template-functions.php';

// ── Namespace aliases (resolved at call time, not load time) ────────────────
use BCC\Onchain\Admin\ChainsPage;
use BCC\Onchain\Admin\SettingsPage;
use BCC\Onchain\Controllers\SignalController;
use BCC\Onchain\Controllers\WalletController;
use BCC\Onchain\Repositories\SignalRepository;
use BCC\Onchain\Services\BonusRetryService;
use BCC\Onchain\Services\BonusService;
use BCC\Onchain\Services\ChainRefreshService;
use BCC\Onchain\Services\MigrationService;
use BCC\Onchain\Services\SignalRefreshService;
use BCC\Onchain\Services\WalletSeedService;

// ══════════════════════════════════════════════════════════════════════════════
// ACTIVATION / DEACTIVATION
// ══════════════════════════════════════════════════════════════════════════════

register_activation_hook(__FILE__, function () {
    if (!file_exists(WP_PLUGIN_DIR . '/bcc-core/bcc-core.php')) {
        wp_die(
            'BCC On-Chain Signals requires the <strong>BCC Core</strong> plugin to be installed.',
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }

    SignalRepository::install_own_table();
    bcc_onchain_ensure_schema();

    if (!wp_next_scheduled('bcc_onchain_daily_refresh')) {
        wp_schedule_event(time(), 'daily', 'bcc_onchain_daily_refresh');
    }
    if (!wp_next_scheduled('bcc_onchain_retry_bonus')) {
        wp_schedule_event(time(), 'hourly', 'bcc_onchain_retry_bonus');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('bcc_onchain_daily_refresh');
    wp_clear_scheduled_hook('bcc_onchain_retry_bonus');
    ChainRefreshService::deactivate();
});

// ══════════════════════════════════════════════════════════════════════════════
// BOOT (plugins_loaded — core deps guaranteed available)
// ══════════════════════════════════════════════════════════════════════════════

add_action('plugins_loaded', function (): void {
    if (!class_exists('BCC\\Core\\PeepSo\\PeepSo') || !class_exists('BCC\\Core\\DB\\DB')) {
        add_action('admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                esc_html('BCC On-Chain Signals'),
                esc_html('requires the BCC Core plugin to be installed and active.')
            );
        });
        return;
    }

    // ── Service locator contracts ───────────────────────────────────────────
    add_filter('bcc.resolve.wallet_link_read', function ($service = null) {
        if ($service instanceof \BCC\Core\Contracts\WalletLinkReadInterface) {
            return $service;
        }
        return new \BCC\Onchain\Services\WalletLinkReadService();
    });

    add_filter('bcc.resolve.wallet_link_write', function ($service = null) {
        if ($service instanceof \BCC\Core\Contracts\WalletLinkWriteInterface) {
            return $service;
        }
        return new \BCC\Onchain\Services\WalletLinkWriteService();
    });

    add_filter('bcc.resolve.onchain_data_read', function ($service = null) {
        if ($service instanceof \BCC\Core\Contracts\OnchainDataReadInterface) {
            return $service;
        }
        return new \BCC\Onchain\Services\OnchainDataReadService();
    });

    // ── Schema migration on version change ────────────────────────────────
    // Runs dbDelta for new columns (next_enrichment_at, decimals, bech32_prefix)
    // without requiring plugin deactivation/reactivation.
    $stored_version = get_option('bcc_onchain_version', '0');
    if (version_compare($stored_version, BCC_ONCHAIN_VERSION, '<')) {
        bcc_onchain_ensure_schema();

        // 1.7.1: One-time fix — reset next_enrichment_at for validators stuck
        // with self_stake=0 from the %f/NULL bug so the enrichment scheduler
        // picks them up immediately instead of waiting for their jittered schedule.
        if (version_compare($stored_version, '1.7.1', '<')) {
            global $wpdb;
            $vTable = \BCC\Onchain\Repositories\ValidatorRepository::table();
            $wpdb->query(
                "UPDATE {$vTable}
                 SET next_enrichment_at = NOW()
                 WHERE (self_stake = 0 OR self_stake IS NULL)
                   AND status != 'inactive'"
            );
        }

        update_option('bcc_onchain_version', BCC_ONCHAIN_VERSION);
    }

    // ── Core service init ───────────────────────────────────────────────────
    ChainRefreshService::init();
    WalletController::init();

    // ── Cron hooks ──────────────────────────────────────────────────────────
    add_action('bcc_onchain_daily_refresh',  [SignalRefreshService::class, 'dailyRefresh']);
    add_action('bcc_onchain_refresh_page',   [SignalRefreshService::class, 'refreshPage']);
    add_action('bcc_onchain_retry_bonus',    [BonusRetryService::class,    'processAll']);

    // ── Domain event hooks ──────────────────────────────────────────────────
    add_action('bcc_onchain_claim_verified', [BonusService::class,      'applyClaimBonus'], 10, 4);
    add_action('bcc_wallet_verified', function (int $userId, string $chain, string $address): void {
        try {
            WalletSeedService::onWalletVerified($userId, $chain, $address);
        } catch (\Throwable $e) {
            // Seed failures must not block the verify response or other listeners.
            // Data will be populated on the next daily cron refresh.
            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-onchain] wallet seed failed, will retry on cron', [
                    'user_id' => $userId, 'chain' => $chain, 'error' => $e->getMessage(),
                ]);
            }
        }
    }, 10, 3);

    // ── REST API ────────────────────────────────────────────────────────────
    add_action('rest_api_init', [SignalController::class, 'registerRoutes']);

    // ── Database migrations ─────────────────────────────────────────────────
    add_action('admin_init', [MigrationService::class, 'maybeUpgrade']);

    // ── Manual cron triggers (admin only) ───────────────────────────────────
    add_action('admin_init', function () {
        if (!current_user_can('manage_options')) {
            return;
        }

        $ran = [];

        if (!empty($_GET['bcc_run_index_validators']) || !empty($_GET['bcc_run_index_all'])) {
            ChainRefreshService::index_validators();
            $ran[] = 'validators';
        }

        if (!empty($_GET['bcc_run_index_collections']) || !empty($_GET['bcc_run_index_all'])) {
            ChainRefreshService::index_collections();
            $ran[] = 'collections';
        }

        if (!empty($_GET['bcc_run_enrich_validators']) || !empty($_GET['bcc_run_index_all'])) {
            ChainRefreshService::refresh_validators();
            $ran[] = 'validator enrichment';
        }

        if (!empty($ran)) {
            $label = implode(' + ', $ran);
            // Show enrichment stats from the run that just completed.
            $enrichStats = get_option('bcc_onchain_enrichment_stats', []);
            add_action('admin_notices', function () use ($label, $enrichStats) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>BCC On-Chain:</strong> Indexing complete (' . esc_html($label) . ').</p>';
                if (!empty($enrichStats)) {
                    printf(
                        '<p>Enrichment: %d processed, %d failed, %d skipped, %d API calls. Stop: %s</p>',
                        (int) ($enrichStats['processed'] ?? 0),
                        (int) ($enrichStats['failed'] ?? 0),
                        (int) ($enrichStats['skipped'] ?? 0),
                        (int) ($enrichStats['api_calls'] ?? 0),
                        esc_html($enrichStats['stopped_reason'] ?? '—')
                    );
                }
                echo '</div>';
            });
        }
    });

    // ── Admin settings ──────────────────────────────────────────────────────
    add_action('admin_menu', function () {
        SettingsPage::register_page();
        ChainsPage::register_page();
    }, 20);
    ChainsPage::register_ajax();
    add_action('admin_init', function () {
        SettingsPage::register_settings();
    });
    add_action('admin_enqueue_scripts', function ($hook) {
        if (strpos($hook, 'bcc-onchain') !== false) {
            wp_enqueue_script('bcc-onchain-admin', BCC_ONCHAIN_URL . 'assets/js/bcc-onchain-admin.js', [], BCC_ONCHAIN_VERSION, true);
            wp_localize_script('bcc-onchain-admin', 'bccOnchain', [
                'restUrl' => esc_url_raw(rest_url('bcc/v1/onchain')),
                'nonce'   => wp_create_nonce('wp_rest'),
            ]);
            wp_enqueue_style('bcc-onchain-admin', BCC_ONCHAIN_URL . 'assets/css/bcc-onchain.css', [], BCC_ONCHAIN_VERSION);
        }
    });

    // ── Shortcode ───────────────────────────────────────────────────────────
    add_shortcode('bcc_onchain_signals', function ($atts) {
        $atts    = shortcode_atts(['page_id' => 0], $atts, 'bcc_onchain_signals');
        $page_id = (int) $atts['page_id'] ?: get_the_ID();
        if (!$page_id) return '';

        $signals = SignalRepository::get_for_page($page_id);

        ob_start();
        include BCC_ONCHAIN_PATH . 'templates/signals-widget.php';
        return ob_get_clean();
    });
}, 20);


// ══════════════════════════════════════════════════════════════════════════════
// SCHEMA HELPER (called from activation + migration — must remain global)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Create/update all plugin database tables.
 */
function bcc_onchain_ensure_schema(): void {
    bcc_onchain_create_chains_table();
    bcc_onchain_create_wallet_links_table();
    bcc_onchain_create_validators_table();
    bcc_onchain_create_collections_table();
    bcc_onchain_create_claims_table();
}



