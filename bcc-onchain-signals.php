<?php
/**
 * Plugin Name: Blue Collar Crypto – On-Chain Signals
 * Description: Enriches BCC trust scores with on-chain data: wallet age, transaction depth, and smart contract deployments (Ethereum & Solana).
 * Version: 1.3.0
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

// Namespace aliases — compile-time only; classes resolved at call time.
use BCC\Core\PeepSo\PeepSo;
use BCC\Core\DB\DB;
use BCC\Onchain\Repositories\SignalRepository;
use BCC\Onchain\Services\SignalFetcher;
use BCC\Onchain\Services\SignalScorer;
use BCC\Onchain\Services\ChainRefreshService;
use BCC\Onchain\Admin\SettingsPage;

define('BCC_ONCHAIN_VERSION', '1.3.0');
define('BCC_ONCHAIN_PATH', plugin_dir_path(__FILE__));
define('BCC_ONCHAIN_URL', plugin_dir_url(__FILE__));

// Signal score caps per category (per-wallet max: 40)
define('BCC_ONCHAIN_MAX_AGE_SCORE',      20);
define('BCC_ONCHAIN_MAX_DEPTH_SCORE',    10);
define('BCC_ONCHAIN_MAX_CONTRACT_SCORE', 10);
define('BCC_ONCHAIN_CACHE_HOURS',        24);   // hours before a re-fetch

// Total on-chain bonus cap per page, across all wallets and chains.
// Even if a user connects many wallets, the trust bonus cannot exceed this.
define('BCC_ONCHAIN_MAX_TOTAL_BONUS',    40);

// Composer autoloader (elliptic-php for EVM/Cosmos signature verification)
$bcc_onchain_autoload = BCC_ONCHAIN_PATH . 'vendor/autoload.php';
if (file_exists($bcc_onchain_autoload)) {
    require_once $bcc_onchain_autoload;
}

require_once BCC_ONCHAIN_PATH . 'includes/class-bcc-onchain-db.php';
require_once BCC_ONCHAIN_PATH . 'includes/class-bcc-onchain-fetcher.php';
require_once BCC_ONCHAIN_PATH . 'includes/class-bcc-onchain-scorer.php';
require_once BCC_ONCHAIN_PATH . 'includes/class-bcc-onchain-settings.php';

// Database schemas
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-chains.php';
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-wallets.php';

// Chain fetchers (driver pattern)
require_once BCC_ONCHAIN_PATH . 'includes/fetchers/interface-bcc-fetcher.php';
require_once BCC_ONCHAIN_PATH . 'includes/fetchers/class-bcc-fetcher-factory.php';
require_once BCC_ONCHAIN_PATH . 'includes/fetchers/class-bcc-fetcher-cosmos.php';
require_once BCC_ONCHAIN_PATH . 'includes/fetchers/class-bcc-fetcher-evm.php';
require_once BCC_ONCHAIN_PATH . 'includes/fetchers/class-bcc-fetcher-solana.php';

// On-chain data tables
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-validators.php';
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-collections.php';
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-dao.php';
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-contracts.php';

// Cron refresh
require_once BCC_ONCHAIN_PATH . 'includes/class-bcc-chain-refresh.php';

// Wallet connect/verify
require_once BCC_ONCHAIN_PATH . 'includes/class-bcc-wallet-connect.php';

// Renderers
require_once BCC_ONCHAIN_PATH . 'includes/renderers/onchain-template-functions.php';

// ── Activation ────────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, function () {
    // Verify bcc-core is at least installed (file exists).
    // We don't gate on is_plugin_active() because during bulk activation
    // the active_plugins option may not be updated yet for this request.
    // The runtime check in bcc_onchain_boot() handles the "not loaded" case.
    if (!file_exists(WP_PLUGIN_DIR . '/bcc-core/bcc-core.php')) {
        wp_die(
            'BCC On-Chain Signals requires the <strong>BCC Core</strong> plugin to be installed.',
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }

    // Create this plugin's own tables (no core dependency).
    SignalRepository::install_own_table();
    bcc_onchain_ensure_schema();

    if (!wp_next_scheduled('bcc_onchain_daily_refresh')) {
        wp_schedule_event(time(), 'daily', 'bcc_onchain_daily_refresh');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('bcc_onchain_daily_refresh');
    ChainRefreshService::deactivate();
});

// ── Boot on plugins_loaded (core deps guaranteed available) ───────────────────
add_action('plugins_loaded', 'bcc_onchain_boot', 20);

function bcc_onchain_boot(): void {
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

    // ── DB upgrade check (runs once per version bump, no re-activation needed) ──
    add_action('admin_init', function () {
        // Clean up legacy option from older versions that managed the bonus column.
        if (get_option('bcc_onchain_needs_bonus_column')) {
            delete_option('bcc_onchain_needs_bonus_column');
        }

        $installed = get_option('bcc_onchain_db_version', '0');
        if (version_compare($installed, BCC_ONCHAIN_VERSION, '<')) {
            bcc_onchain_ensure_schema();
            update_option('bcc_onchain_db_version', BCC_ONCHAIN_VERSION);
        }
    });

    // ── Admin settings page ─────────────────────────────────────────────────────
    add_action('admin_menu', function () {
        SettingsPage::register_page();
    }, 20);
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

    // ── REST API ────────────────────────────────────────────────────────────────
    add_action('rest_api_init', function () {
        // GET /bcc/v1/onchain/{page_id} — fetch stored signals for a page
        register_rest_route('bcc/v1', '/onchain/(?P<page_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'bcc_onchain_rest_get',
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        // POST /bcc/v1/onchain/{page_id}/refresh — admin trigger re-fetch
        register_rest_route('bcc/v1', '/onchain/(?P<page_id>\d+)/refresh', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'bcc_onchain_rest_refresh',
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
    });

    // ── Initial fetch on wallet verification ────────────────────────────────────
    // Fires once when a user verifies a wallet. Populates on-chain data
    // immediately so the widget is never empty after verification.
    add_action('bcc_wallet_verified', function (int $user_id, string $chain, string $address) {

        if (!in_array($chain, bcc_onchain_supported_chains(), true)) {
            return;
        }

        // If we already have a stored row for this wallet, skip the API call.
        if (SignalRepository::get_permanent($address, $chain)) {
            return;
        }

        $page_id = bcc_onchain_get_page_for_user($user_id);

        if ($page_id) {
            // Fetch only the newly verified wallet, not every wallet on the page.
            bcc_onchain_fetch_and_store_wallet($user_id, $page_id, $chain, $address);
        } else {
            // User has no page yet — store the wallet data directly.
            $signals = SignalFetcher::fetch($address, $chain);
            if ($signals !== null) {
                SignalRepository::upsert(array_merge($signals, [
                    'user_id'            => $user_id,
                    'wallet_address'     => $address,
                    'chain'              => $chain,
                    'score_contribution' => SignalScorer::score($signals),
                ]));
            }
        }
    }, 10, 3);

    // ── Cron: daily refresh of all pages with wallets ────────────────────────────
    add_action('bcc_onchain_daily_refresh', function () {
        global $wpdb;

        $verif_table  = DB::table('trust_user_verifications');
        $supported    = bcc_onchain_supported_chains();
        $wallet_types = array_map(fn($c) => 'wallet_' . $c, $supported);
        $placeholders = implode(',', array_fill(0, count($wallet_types), '%s'));

        $batch_size = 100;
        $offset     = 0;
        $stagger    = 0;

        // Process owners in batches to limit memory usage
        do {
            $args   = array_merge($wallet_types, [$batch_size, $offset]);
            $owners = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$verif_table}
                 WHERE type IN ({$placeholders})
                   AND status = 'active'
                 LIMIT %d OFFSET %d",
                ...$args
            ));

            if (empty($owners)) {
                break;
            }

            foreach ($owners as $owner_id) {
                $page_id = bcc_onchain_get_page_for_user((int) $owner_id);

                if ($page_id && !wp_next_scheduled('bcc_onchain_refresh_page', [$page_id])) {
                    wp_schedule_single_event(time() + (10 * $stagger), 'bcc_onchain_refresh_page', [$page_id]);
                    $stagger++;
                }
            }

            $offset += $batch_size;
        } while (count($owners) === $batch_size);
    });

    add_action('bcc_onchain_refresh_page', function (int $page_id) {
        bcc_onchain_fetch_and_store($page_id, false);
    });

    // ── Shortcode: [bcc_onchain_signals page_id="123"] ──────────────────────────
    add_shortcode('bcc_onchain_signals', function ($atts) {
        $atts    = shortcode_atts(['page_id' => 0], $atts, 'bcc_onchain_signals');
        $page_id = (int) $atts['page_id'] ?: get_the_ID();
        if (!$page_id) return '';

        $signals = SignalRepository::get_for_page($page_id);

        ob_start();
        include BCC_ONCHAIN_PATH . 'templates/signals-widget.php';
        return ob_get_clean();
    });
}

// ── Schema helper ─────────────────────────────────────────────────────────────

/**
 * Create/update all plugin database tables.
 * Single source of truth — called from activation and admin_init upgrade check.
 */
function bcc_onchain_ensure_schema(): void {
    bcc_onchain_create_chains_table();
    bcc_onchain_create_wallet_links_table();
    bcc_onchain_create_validators_table();
    bcc_onchain_create_collections_table();
    bcc_onchain_create_dao_tables();
    bcc_onchain_create_contracts_table();
}

// ── Supported chains ──────────────────────────────────────────────────────────

/**
 * Canonical list of chains the on-chain signals fetcher supports.
 * Used by the wallet-verified hook and daily cron to decide which chains to
 * process. Add a chain here once its fetcher driver is ready.
 *
 * @return string[]
 */
function bcc_onchain_supported_chains(): array {
    return ['ethereum', 'solana'];
}

// ── REST callbacks ────────────────────────────────────────────────────────────

function bcc_onchain_rest_get(WP_REST_Request $req): WP_REST_Response
{
    $page_id = (int) $req->get_param('page_id');
    $data    = SignalRepository::get_for_page($page_id);
    return rest_ensure_response($data);
}

function bcc_onchain_rest_refresh(WP_REST_Request $req): WP_REST_Response
{
    $page_id = (int) $req->get_param('page_id');
    $results = bcc_onchain_fetch_and_store($page_id, true); // force refresh
    return rest_ensure_response(['refreshed' => count($results), 'signals' => $results]);
}

// ── Public helpers ────────────────────────────────────────────────────────────

/**
 * Fetch (or load from cache) on-chain signals for all wallets connected to
 * the owner of $page_id, store them, and return the rows.
 *
 * @param int  $page_id
 * @param bool $force   Skip all caches (DB + transient) and force API calls
 * @return array        Array of signal rows
 */
function bcc_onchain_fetch_and_store(int $page_id, bool $force = false): array
{
    // Resolve page owner
    $owner_id = PeepSo::get_page_owner($page_id);
    if (!$owner_id) {
        return [];
    }

    // Get connected wallets from the trust engine's verifications table
    $wallets = SignalFetcher::get_connected_wallets($owner_id);
    $results = [];

    foreach ($wallets as $chain => $addresses) {
        foreach ($addresses as $address) {
            $cached = $force ? null : SignalRepository::get_cached($address, $chain);

            if ($cached) {
                $results[] = $cached;
                continue;
            }

            $signals = SignalFetcher::fetch($address, $chain, $force);
            if ($signals === null) {
                continue; // API error — skip
            }

            $score = SignalScorer::score($signals);
            $row   = array_merge($signals, [
                'user_id'            => $owner_id,
                'wallet_address'     => $address,
                'chain'              => $chain,
                'score_contribution' => $score,
            ]);

            SignalRepository::upsert($row);
            $results[] = $row;
        }
    }

    // Persist the total bonus to the trust score, capped at the global maximum
    if (!empty($results)) {
        $total_bonus = array_sum(array_column($results, 'score_contribution'));
        $total_bonus = min($total_bonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);
        bcc_onchain_apply_bonus($page_id, $total_bonus);
    }

    return $results;
}

/**
 * Fetch and store signals for a single wallet, then recalculate the page bonus.
 * Avoids re-fetching every wallet when only one is newly verified.
 *
 * @param int    $user_id
 * @param int    $page_id
 * @param string $chain
 * @param string $address
 * @param bool   $force   Skip all caches and force API calls
 * @return array|null      Signal row, or null on API error
 */
function bcc_onchain_fetch_and_store_wallet(int $user_id, int $page_id, string $chain, string $address, bool $force = false): ?array
{
    $cached = $force ? null : SignalRepository::get_cached($address, $chain);
    if ($cached) {
        return $cached;
    }

    $signals = SignalFetcher::fetch($address, $chain, $force);
    if ($signals === null) {
        return null;
    }

    $score = SignalScorer::score($signals);
    $row   = array_merge($signals, [
        'user_id'            => $user_id,
        'wallet_address'     => $address,
        'chain'              => $chain,
        'score_contribution' => $score,
    ]);

    SignalRepository::upsert($row);

    // Recalculate total bonus from all wallets for this page
    $all_signals = SignalRepository::get_for_page($page_id);
    $total_bonus = array_sum(array_column($all_signals, 'score_contribution'));
    $total_bonus = min($total_bonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);
    bcc_onchain_apply_bonus($page_id, $total_bonus);

    return $row;
}

/**
 * Apply the on-chain bonus to the stored trust score.
 *
 * Delegates to bcc-trust-engine via ScoreContributorInterface so this
 * plugin never writes to trust tables directly.
 */
function bcc_onchain_apply_bonus(int $page_id, float $bonus): void
{
    $contributor = class_exists('\\BCC\\Core\\ServiceLocator') ? \BCC\Core\ServiceLocator::resolveScoreContributor() : null;

    if (!$contributor) {
        error_log('[BCC Onchain] ScoreContributorInterface unavailable — bonus not applied for page ' . $page_id);
        return;
    }

    $contributor->applyBonus($page_id, 'onchain', $bonus);
}

/**
 * Look up the page_id belonging to a user.
 * Checks trust_page_scores first, falls back to wp_posts for peepso-page CPT.
 *
 * @param int $user_id
 * @return int Page ID, or 0 if not found.
 */
function bcc_onchain_get_page_for_user(int $user_id): int
{
    global $wpdb;

    $scores_table = DB::table('trust_page_scores');
    $page_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT page_id FROM {$scores_table} WHERE page_owner_id = %d LIMIT 1",
        $user_id
    ));

    if ($page_id) {
        return $page_id;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_author = %d AND post_type = 'peepso-page' AND post_status = 'publish'
         LIMIT 1",
        $user_id
    ));
}
