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
use BCC\Core\ServiceLocator;
use BCC\Onchain\Repositories\CollectionRepository;
use BCC\Onchain\Repositories\SignalRepository;
use BCC\Onchain\Repositories\ValidatorRepository;
use BCC\Onchain\Repositories\WalletRepository;
use BCC\Onchain\Services\SignalFetcher;
use BCC\Onchain\Services\SignalScorer;
use BCC\Onchain\Services\ChainRefreshService;
use BCC\Onchain\Controllers\WalletController;
use BCC\Onchain\Admin\SettingsPage;

define('BCC_ONCHAIN_VERSION', '1.6.1');
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

// Database schemas
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-chains.php';
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-wallets.php';

// On-chain data tables
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-validators.php';
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-collections.php';
require_once BCC_ONCHAIN_PATH . 'includes/database/schema-claims.php';

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
    if (!wp_next_scheduled('bcc_onchain_retry_bonus')) {
        wp_schedule_event(time(), 'hourly', 'bcc_onchain_retry_bonus');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('bcc_onchain_daily_refresh');
    wp_clear_scheduled_hook('bcc_onchain_retry_bonus');
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

    // ── Expose wallet link data to the ecosystem via bcc-core contract ────────
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

    // ── Register cron + wallet hooks (single entry point for all init) ────────
    ChainRefreshService::init();
    WalletController::init();

    // ── Bonus retry cron — processes failed bonus applications hourly ────────
    add_action('bcc_onchain_retry_bonus', 'bcc_onchain_process_bonus_retries');

    // ── Claim → Trust integration: apply trust bonus when a claim is verified ──
    add_action('bcc_onchain_claim_verified', 'bcc_onchain_apply_claim_bonus', 10, 4);

    // ── DB upgrade check (runs once per version bump, no re-activation needed) ──
    add_action('admin_init', function () {
        // Clean up legacy option from older versions that managed the bonus column.
        if (get_option('bcc_onchain_needs_bonus_column')) {
            delete_option('bcc_onchain_needs_bonus_column');
        }

        $installed = get_option('bcc_onchain_db_version', '0');
        if (version_compare($installed, BCC_ONCHAIN_VERSION, '<')) {
            bcc_onchain_ensure_schema();

            // dbDelta cannot ALTER existing columns — run explicit migrations.
            global $wpdb;
            $wpdb->query("ALTER TABLE " . bcc_onchain_validators_table() . " MODIFY wallet_link_id BIGINT UNSIGNED DEFAULT NULL");
            $wpdb->query("ALTER TABLE " . bcc_onchain_collections_table() . " MODIFY wallet_link_id BIGINT UNSIGNED DEFAULT NULL");

            // Add image_url column if missing (new in 1.6.0).
            $cols = $wpdb->get_col("SHOW COLUMNS FROM " . bcc_onchain_collections_table());
            if (!in_array('image_url', $cols, true)) {
                $wpdb->query("ALTER TABLE " . bcc_onchain_collections_table() . " ADD COLUMN image_url VARCHAR(500) DEFAULT NULL AFTER metadata_storage");
            }

            update_option('bcc_onchain_db_version', BCC_ONCHAIN_VERSION);
        }
    });

    // ── Manual cron triggers (admin only) ────────────────────────────────────
    //   ?bcc_run_index_validators=1   → bulk index all Cosmos validators
    //   ?bcc_run_index_collections=1  → bulk index top NFT collections
    //   ?bcc_run_index_all=1          → both
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

        if (!empty($ran)) {
            $label = implode(' + ', $ran);
            add_action('admin_notices', function () use ($label) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>BCC On-Chain:</strong> Indexing complete (' . esc_html($label) . '). Check the database tables.</p></div>';
            });
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
        // Per-request guard: prevent double processing if both REST and AJAX
        // paths fire the hook for the same wallet in a single request.
        static $processed = [];
        $key = $user_id . ':' . $chain . ':' . strtolower($address);
        if (isset($processed[$key])) {
            return;
        }
        $processed[$key] = true;

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
                    'score_contribution' => min(SignalScorer::score($signals), BCC_ONCHAIN_MAX_TOTAL_BONUS),
                ]));
            }
        }

        // Seed on-chain entity data on first wallet verification.
        // Runs after signal fetch so the wallet_link row exists.
        $chainObj = \BCC\Onchain\Repositories\ChainRepository::getBySlug($chain);
        if ($chainObj && \BCC\Onchain\Factories\FetcherFactory::has_driver($chainObj->chain_type)) {
            $fetcher = \BCC\Onchain\Factories\FetcherFactory::make_for_chain($chainObj);

            // Find the wallet_link row for this address.
            $walletLink = null;
            $wallets = WalletRepository::getForUser($user_id);
            foreach ($wallets as $w) {
                if (strtolower($w->wallet_address) === strtolower($address)) {
                    $walletLink = $w;
                    break;
                }
            }

            if ($walletLink) {
                // Seed validator data (Cosmos chains).
                if ($fetcher->supports_feature('validator')) {
                    $validatorData = $fetcher->fetch_validator($address);
                    if (!empty($validatorData)) {
                        ValidatorRepository::upsert($validatorData, (int) $walletLink->id, HOUR_IN_SECONDS);
                    }
                }

                // Seed NFT collection data (all chains).
                if ($fetcher->supports_feature('collection')) {
                    $collections = $fetcher->fetch_collections($address, (int) $chainObj->id);
                    foreach ($collections as $c) {
                        CollectionRepository::upsert($c, (int) $walletLink->id, 4 * HOUR_IN_SECONDS);
                    }
                    if (!empty($collections) && (int) $walletLink->post_id > 0) {
                        \BCC\Onchain\Services\CollectionService::invalidate((int) $walletLink->post_id);
                    }
                }
            }
        }
    }, 10, 3);

    // ── Cron: daily refresh of all pages with wallets ────────────────────────────
    add_action('bcc_onchain_daily_refresh', function () {
        // Also process any pending bonus retries from failed applications.
        bcc_onchain_process_bonus_retries();

        $walletService = ServiceLocator::resolveWalletVerificationRead();

        $supported  = bcc_onchain_supported_chains();
        $batch_size = 100;
        $offset     = 0;
        $stagger    = 0;

        // Process owners in batches to limit memory usage
        do {
            $owners = $walletService->getUserIdsWithWallets($supported, $batch_size, $offset);

            if (empty($owners)) {
                break;
            }

            foreach ($owners as $owner_id) {
                $page_id = bcc_onchain_get_page_for_user($owner_id);

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
    bcc_onchain_create_claims_table();
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
 * Apply a trust bonus when an on-chain claim is verified.
 *
 * Resolves the page linked to the entity (via wallet_link.post_id),
 * then recomputes the TOTAL on-chain bonus (signals + claims combined)
 * and applies it via the existing ScoreContributor pipeline.
 *
 * This avoids overwriting the signal bonus — applyBonus() does SET not ADD,
 * so we must always pass the full combined value.
 *
 * Claim bonus values by role:
 *   operator/creator: +5.0
 *   holder:           +1.0
 */
function bcc_onchain_apply_claim_bonus(int $userId, string $entityType, int $entityId, string $role): void {
    // Guard: table functions must exist (schema migration must have run).
    if (!function_exists('bcc_onchain_claims_table')
        || !function_exists('bcc_onchain_validators_table')
    ) {
        return;
    }

    global $wpdb;

    $bonusMap = [
        'operator' => 5.0,
        'creator'  => 5.0,
        'holder'   => 1.0,
    ];

    $claimBonus = $bonusMap[$role] ?? 0.0;
    if ($claimBonus <= 0.0) {
        return;
    }

    // Resolve page_id: entity → wallet_link → post_id.
    $entityTable = ($entityType === 'validator')
        ? bcc_onchain_validators_table()
        : bcc_onchain_collections_table();

    $walletTable = \BCC\Core\DB\DB::table('wallet_links');

    $pageId = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT w.post_id
         FROM {$entityTable} e
         JOIN {$walletTable} w ON w.id = e.wallet_link_id
         WHERE e.id = %d
         LIMIT 1",
        $entityId
    ));

    if (!$pageId) {
        return;
    }

    // Recompute full bonus: existing signal scores + all claim bonuses for this page.
    $signalBonus = array_sum(array_column(
        SignalRepository::get_for_page($pageId),
        'score_contribution'
    ));

    $claimsTable = bcc_onchain_claims_table();
    $totalClaimBonus = (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(CASE
            WHEN cl.claim_role IN ('operator','creator') THEN 5.0
            WHEN cl.claim_role = 'holder' THEN 1.0
            ELSE 0
         END), 0)
         FROM {$claimsTable} cl
         JOIN {$entityTable} e ON e.id = cl.entity_id AND cl.entity_type = %s
         JOIN {$walletTable} w ON w.id = e.wallet_link_id
         WHERE w.post_id = %d AND cl.status = 'verified'",
        $entityType,
        $pageId
    ));

    $totalBonus = min($signalBonus + $totalClaimBonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);

    bcc_onchain_apply_bonus($pageId, $totalBonus);
}

/**
 * Apply the on-chain bonus to the stored trust score.
 *
 * Delegates to bcc-trust-engine via ScoreContributorInterface so this
 * plugin never writes to trust tables directly.
 *
 * If the trust engine is unavailable or the write fails, the page_id is
 * queued for retry via a transient-backed pending list. The daily cron
 * (bcc_onchain_daily_refresh) and a dedicated retry hook process the queue.
 */
function bcc_onchain_apply_bonus(int $page_id, float $bonus): void
{
    if (!class_exists('\\BCC\\Core\\ServiceLocator')
        || !\BCC\Core\ServiceLocator::hasRealService(\BCC\Core\Contracts\ScoreContributorInterface::class)
    ) {
        bcc_onchain_queue_bonus_retry($page_id, $bonus);
        return;
    }

    $contributor = \BCC\Core\ServiceLocator::resolveScoreContributor();
    $applied = $contributor->applyBonus($page_id, 'onchain', $bonus);

    if (!$applied) {
        bcc_onchain_queue_bonus_retry($page_id, $bonus);
        return;
    }

    // Success — clear any pending retry for this page
    bcc_onchain_clear_bonus_retry($page_id);
}

/**
 * Queue a failed bonus application for retry.
 *
 * Stores pending retries in a transient (auto-expires after 24h as a
 * safety net). The retry cron processes the queue idempotently: it
 * recalculates the bonus from stored signals, so stale values are
 * impossible.
 */
function bcc_onchain_queue_bonus_retry(int $page_id, float $bonus): void
{
    $pending = get_option('bcc_onchain_pending_bonus', []);
    $pending[$page_id] = [
        'bonus'    => $bonus,
        'queued_at' => time(),
        'attempts' => ($pending[$page_id]['attempts'] ?? 0) + 1,
    ];
    update_option('bcc_onchain_pending_bonus', $pending, false);

    if (class_exists('BCC\\Core\\Log\\Logger')) {
        \BCC\Core\Log\Logger::error('[bcc-onchain-signals] bonus_queued_for_retry', [
            'page_id'  => $page_id,
            'bonus'    => $bonus,
            'attempts' => $pending[$page_id]['attempts'],
        ]);
    }
}

/**
 * Clear a page from the pending bonus retry queue.
 */
function bcc_onchain_clear_bonus_retry(int $page_id): void
{
    $pending = get_option('bcc_onchain_pending_bonus', []);
    if (isset($pending[$page_id])) {
        unset($pending[$page_id]);
        update_option('bcc_onchain_pending_bonus', $pending, false);
    }
}

/**
 * Process all pending bonus retries.
 *
 * Idempotent: recalculates the bonus from stored signals (source of
 * truth) rather than using the stale queued value.
 */
function bcc_onchain_process_bonus_retries(): void
{
    $pending = get_option('bcc_onchain_pending_bonus', []);
    if (empty($pending)) {
        return;
    }

    // Check that bcc-core is loaded AND a real ScoreContributor is registered
    // (not the NullScoreContributor fallback). Without a real provider, retries
    // would burn attempts against the NullObject's always-false applyBonus().
    if (!class_exists('\\BCC\\Core\\ServiceLocator')) {
        return; // bcc-core not loaded at all
    }

    $contributor = \BCC\Core\ServiceLocator::resolveScoreContributor();

    if (!\BCC\Core\ServiceLocator::hasRealService(\BCC\Core\Contracts\ScoreContributorInterface::class)) {
        return; // Trust engine not active — retry next cycle without burning attempts
    }

    $max_attempts = 5;

    foreach ($pending as $page_id => $entry) {
        if (($entry['attempts'] ?? 0) >= $max_attempts) {
            // Give up after max attempts — log and remove
            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-onchain-signals] bonus_retry_exhausted', [
                    'page_id'  => $page_id,
                    'attempts' => $entry['attempts'],
                ]);
            }
            unset($pending[$page_id]);
            continue;
        }

        // Recalculate bonus from stored signals (idempotent, no stale data)
        $all_signals = SignalRepository::get_for_page((int) $page_id);
        $total_bonus = array_sum(array_column($all_signals, 'score_contribution'));
        $total_bonus = min($total_bonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);

        $applied = $contributor->applyBonus((int) $page_id, 'onchain', $total_bonus);

        if ($applied) {
            unset($pending[$page_id]);
        } else {
            $pending[$page_id]['attempts'] = ($entry['attempts'] ?? 0) + 1;
        }
    }

    update_option('bcc_onchain_pending_bonus', $pending, false);
}

/**
 * Look up the page_id belonging to a user.
 *
 * @deprecated Use ServiceLocator::resolvePageOwnerResolver()->getPageForOwner() instead.
 *
 * @param int $user_id
 * @return int Page ID, or 0 if not found.
 */
function bcc_onchain_get_page_for_user(int $user_id): int
{
    if (!class_exists('\\BCC\\Core\\ServiceLocator')) {
        // bcc-core not loaded — raw WP fallback.
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_author = %d AND post_type = 'peepso-page' AND post_status = 'publish'
             LIMIT 1",
            $user_id
        ));
    }

    // NullPageOwnerResolver::getPageForOwner() performs the same WP query
    // as the fallback above, so no separate path is needed.
    return \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($user_id);
}
