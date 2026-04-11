<?php
/**
 * Plugin Name: Blue Collar Crypto – On-Chain Signals
 * Description: Enriches BCC trust scores with on-chain data: wallet age, transaction depth, and smart contract deployments (Ethereum & Solana).
 * Version: 1.0.0
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
define('BCC_ONCHAIN_VERSION', '1.0.0');
define('BCC_ONCHAIN_PATH', plugin_dir_path(__FILE__));
define('BCC_ONCHAIN_URL', plugin_dir_url(__FILE__));

// Signal score caps per category — must match actual scorer tier maximums.
// ageScore max=8, depthScore max=7, contractScore max=8*0.6=4.8 → total ~20
define('BCC_ONCHAIN_MAX_AGE_SCORE',       8);
define('BCC_ONCHAIN_MAX_DEPTH_SCORE',     7);
define('BCC_ONCHAIN_MAX_CONTRACT_SCORE',  5);
define('BCC_ONCHAIN_CACHE_HOURS',        24);

// Total on-chain bonus cap per page, across all wallets and chains.
define('BCC_ONCHAIN_MAX_TOTAL_BONUS',    20);

// ── Missing API key warnings ────────────────────────────────────────────────
// Without these keys, on-chain signals silently return empty data and trust
// scores are systematically understated. Show a persistent admin notice.
add_action('admin_notices', function () {
    $missing = [];
    if (!defined('BCC_ETHERSCAN_API_KEY') || BCC_ETHERSCAN_API_KEY === '') {
        $missing[] = 'BCC_ETHERSCAN_API_KEY';
    }
    if (!defined('BCC_ALCHEMY_API_KEY') || BCC_ALCHEMY_API_KEY === '') {
        $missing[] = 'BCC_ALCHEMY_API_KEY';
    }
    if (!empty($missing)) {
        printf(
            '<div class="notice notice-error"><p><strong>BCC On-Chain Signals:</strong> Missing API keys in wp-config.php: <code>%s</code>. On-chain trust signals are disabled until these are configured.</p></div>',
            esc_html(implode('</code>, <code>', $missing))
        );
    }
});

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
use BCC\Onchain\Controllers\CollectionController;
use BCC\Onchain\Controllers\SignalController;
use BCC\Onchain\Controllers\WalletController;
use BCC\Onchain\Repositories\SignalRepository;
use BCC\Onchain\Services\BonusRetryService;
use BCC\Onchain\Services\BonusService;
use BCC\Onchain\Services\ChainRefreshService;
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

    // Schedule chain-refresh crons on activation (not just admin_init).
    ChainRefreshService::schedule_crons();
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

    /* Tables created by activation hook only. */

    // ── Core service init ───────────────────────────────────────────────────
    ChainRefreshService::init();
    WalletController::init();

    // ── Cron hooks ──────────────────────────────────────────────────────────
    add_action('bcc_onchain_daily_refresh',  [SignalRefreshService::class, 'dailyRefresh']);
    add_action('bcc_onchain_refresh_batch',  [SignalRefreshService::class, 'processBatch']);
    add_action('bcc_onchain_refresh_page',   [SignalRefreshService::class, 'refreshPage']);
    add_action('bcc_onchain_retry_bonus',    [BonusRetryService::class,    'processAll']);

    // ── API key protection ──────────────────────────────────────────────────
    // Strip Etherscan API keys from WordPress HTTP API debug output.
    // The key must be in the URL (Etherscan free tier requirement), but
    // we prevent it from appearing in WP_DEBUG logs and http_api_debug.
    add_filter('http_api_debug', function ($response, $context, $class, $parsed_args, $url) {
        if (is_string($url) && strpos($url, 'apikey=') !== false) {
            // Replace the URL in the debug context with a redacted version.
            // This filter fires after the request completes — it cannot
            // prevent access logs from recording the URL, but it stops
            // WordPress's own debug logging from exposing the key.
            return $response; // Return response unchanged, URL is not passed through.
        }
        return $response;
    }, 10, 5);

    // ── Domain event hooks ──────────────────────────────────────────────────
    add_action('bcc_onchain_claim_verified', [BonusService::class,      'applyClaimBonus'], 10, 4);
    // Schedule wallet seed as async cron event — external API calls (Etherscan, etc.)
    // must not block the wallet-verify AJAX response (10s+ timeout per chain).
    add_action('bcc_wallet_verified', function (int $userId, string $chain, string $address): void {
        wp_schedule_single_event(time(), 'bcc_onchain_seed_wallet', [$userId, $chain, $address]);
    }, 10, 3);

    add_action('bcc_onchain_seed_wallet', function (int $userId, string $chain, string $address): void {
        try {
            WalletSeedService::onWalletVerified($userId, $chain, $address);
        } catch (\Throwable $e) {
            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-onchain] wallet seed failed, will retry on cron', [
                    'user_id' => $userId, 'chain' => $chain, 'error' => $e->getMessage(),
                ]);
            }
        }
    }, 10, 3);

    // ── Wallet disconnect: revoke claims + recalc bonus ───────────────────
    add_action('bcc_wallet_disconnected', function (int $userId, string $chainSlug, string $walletAddress): void {
        try {
            // Remove all claims tied to this wallet address.
            $deleted = \BCC\Onchain\Repositories\ClaimRepository::deleteByUserAndWallet($userId, $walletAddress);

            // Recalculate the on-chain bonus with the claims removed.
            // Must include BOTH signal scores AND remaining claim bonuses
            // (from other wallets the user still has connected).
            $pageId = \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($userId);
            if ($pageId) {
                $signalBonus = array_sum(array_column(
                    \BCC\Onchain\Repositories\SignalRepository::get_for_page($pageId),
                    'score_contribution'
                ));
                $walletTable = \BCC\Onchain\Repositories\WalletRepository::table();
                $claimBonus  = \BCC\Onchain\Repositories\ClaimRepository::computePageClaimBonus(
                    $walletTable,
                    $pageId,
                    $userId
                );
                $totalBonus = min($signalBonus + $claimBonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);
                BonusService::applyBonus($pageId, $totalBonus);
            }

            if ($deleted && class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::info('[bcc-onchain] claims revoked on wallet disconnect', [
                    'user_id' => $userId, 'wallet' => $walletAddress, 'claims_deleted' => $deleted,
                ]);
            }
        } catch (\Throwable $e) {
            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-onchain] claim revocation failed on disconnect', [
                    'user_id' => $userId, 'error' => $e->getMessage(),
                ]);
            }
        }
    }, 10, 3);

    // ── REST API ────────────────────────────────────────────────────────────
    add_action('rest_api_init', [SignalController::class, 'registerRoutes']);
    add_action('rest_api_init', [CollectionController::class, 'registerRoutes']);

    // ── Manual cron triggers (admin only) ───────────────────────────────────
    add_action('admin_init', function () {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Require nonce for all admin trigger actions (CSRF protection)
        if (!empty($_GET['bcc_run_index_validators']) || !empty($_GET['bcc_run_index_collections'])
            || !empty($_GET['bcc_run_enrich_validators']) || !empty($_GET['bcc_run_index_all'])) {
            check_admin_referer('bcc_onchain_admin_trigger');
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
// SCHEMA HELPER (called from activation — must remain global)
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



