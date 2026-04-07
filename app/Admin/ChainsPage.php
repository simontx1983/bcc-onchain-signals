<?php

namespace BCC\Onchain\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Repositories\ChainRepository;
use BCC\Onchain\Repositories\ValidatorRepository;
use BCC\Onchain\Factories\FetcherFactory;

/**
 * Admin page: Chains
 *
 * Lists all chains in the database with validator counts,
 * last indexed time, and a "Refresh" button per chain that
 * triggers a fresh API call to re-index that chain's validators.
 */
class ChainsPage
{
    const PAGE_SLUG = 'bcc-onchain-chains';

    public static function register_page(): void
    {
        add_submenu_page(
            'bcc-trust-dashboard',
            'Chains',
            'Chains',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    /**
     * Register the AJAX handler for per-chain refresh.
     */
    public static function register_ajax(): void
    {
        add_action('wp_ajax_bcc_chain_refresh', [self::class, 'ajax_refresh']);
    }

    /**
     * AJAX: Re-index validators for a single chain.
     */
    public static function ajax_refresh(): void
    {
        check_ajax_referer('bcc_chain_refresh', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $chainId = (int) ($_POST['chain_id'] ?? 0);
        $chain   = ChainRepository::getById($chainId);

        if (!$chain) {
            wp_send_json_error(['message' => 'Chain not found.']);
        }

        if (!FetcherFactory::has_driver($chain->chain_type)) {
            wp_send_json_error(['message' => "No fetcher driver for chain type: {$chain->chain_type}"]);
        }

        try {
            $fetcher = FetcherFactory::make_for_chain($chain);

            if (!method_exists($fetcher, 'fetch_all_validators')) {
                wp_send_json_error(['message' => 'This chain type does not support validator indexing.']);
            }

            // Step 1: Index — fetch all validators and upsert.
            $validators = $fetcher->fetch_all_validators();

            if (empty($validators)) {
                wp_send_json_success([
                    'message' => "No validators returned for {$chain->name}.",
                    'stats'   => ['total' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'enriched' => 0],
                ]);
                return;
            }

            $stats = ValidatorRepository::bulkUpsert($validators, 4 * HOUR_IN_SECONDS);

            // Step 2: Full enrichment — fetch ALL data (self_stake, uptime, delegators)
            // for every validator. Uses fetch_validator() which has no skip-if-fresh
            // logic, so every field gets a fresh API call regardless of age.
            $enriched = 0;
            $enrichErrors = 0;

            if ($fetcher->supports_feature('validator')) {
                global $wpdb;
                $vTable = ValidatorRepository::table();
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$vTable}
                     WHERE chain_id = %d AND status != 'inactive'
                     ORDER BY total_stake DESC
                     LIMIT 500",
                    (int) $chain->id
                ));

                foreach ($rows as $row) {
                    try {
                        $data = $fetcher->fetch_validator($row->operator_address);
                        if (!empty($data)) {
                            ValidatorRepository::enrichByOperator($data, HOUR_IN_SECONDS);
                            $enriched++;
                        }
                    } catch (\Throwable $e) {
                        $enrichErrors++;
                    }
                }
            }

            $stats['enriched'] = $enriched;

            wp_send_json_success([
                'message' => sprintf(
                    '%s: %d indexed (%d new, %d updated), %d enriched%s.',
                    $chain->name,
                    $stats['total'],
                    $stats['new'],
                    $stats['updated'],
                    $enriched,
                    $enrichErrors > 0 ? ", {$enrichErrors} enrich errors" : ''
                ),
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $chain->name . ': ' . $e->getMessage()]);
        }
    }

    /**
     * Render the Chains admin page.
     */
    public static function render_page(): void
    {
        global $wpdb;

        // Get all chains (not just active).
        $table  = ChainRepository::table();
        $chains = $wpdb->get_results("SELECT * FROM {$table} ORDER BY chain_type, name");

        // Get validator counts per chain.
        $vTable = ValidatorRepository::table();
        $counts = $wpdb->get_results(
            "SELECT chain_id, COUNT(*) as cnt,
                    MAX(fetched_at) as last_fetched
             FROM {$vTable}
             GROUP BY chain_id"
        );

        $countMap = [];
        foreach ($counts as $row) {
            $countMap[(int) $row->chain_id] = $row;
        }

        $nonce = wp_create_nonce('bcc_chain_refresh');
        ?>
        <div class="wrap">
            <h1>Chains</h1>
            <p>All chains registered in the database. Click <strong>Refresh</strong> to re-index validators for a specific chain.</p>

            <table class="widefat striped" style="max-width:1100px">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Type</th>
                        <th>Token</th>
                        <th>Decimals</th>
                        <th>Validators</th>
                        <th>Last Indexed</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chains as $chain):
                        $cid       = (int) $chain->id;
                        $info      = $countMap[$cid] ?? null;
                        $valCount  = $info ? (int) $info->cnt : 0;
                        $lastFetch = $info->last_fetched ?? null;
                        $hasDriver = FetcherFactory::has_driver($chain->chain_type);
                        $isActive  = (int) $chain->is_active;
                    ?>
                    <tr data-chain-id="<?php echo esc_attr((string) $cid); ?>">
                        <td><?php echo esc_html((string) $cid); ?></td>
                        <td><strong><?php echo esc_html($chain->name); ?></strong></td>
                        <td><code><?php echo esc_html($chain->slug); ?></code></td>
                        <td><code><?php echo esc_html($chain->chain_type); ?></code></td>
                        <td><?php echo esc_html($chain->native_token ?? '—'); ?></td>
                        <td><?php echo esc_html((string) ($chain->decimals ?? '—')); ?></td>
                        <td><?php echo esc_html((string) $valCount); ?></td>
                        <td><?php echo $lastFetch ? esc_html($lastFetch) : '<em>Never</em>'; ?></td>
                        <td>
                            <?php if (!$isActive): ?>
                                <span style="color:#d63638;">Inactive</span>
                            <?php elseif (!$hasDriver): ?>
                                <span style="color:#dba617;">No Driver</span>
                            <?php else: ?>
                                <span style="color:#00a32a;">Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isActive && $hasDriver): ?>
                            <button class="button bcc-chain-refresh-btn"
                                    data-chain-id="<?php echo esc_attr((string) $cid); ?>"
                                    data-chain-name="<?php echo esc_attr($chain->name); ?>">
                                Refresh
                            </button>
                            <span class="bcc-chain-status" style="margin-left:8px;font-size:12px;"></span>
                            <?php else: ?>
                            <span style="color:#94a3b8;font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:16px">
                <button class="button button-primary" id="bcc-refresh-all-chains">Refresh All Chains</button>
                <span id="bcc-refresh-all-status" style="margin-left:12px;font-size:13px;"></span>
            </p>
        </div>

        <script>
        (function() {
            var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var nonce   = '<?php echo esc_js($nonce); ?>';

            function refreshChain(btn) {
                var chainId   = btn.getAttribute('data-chain-id');
                var chainName = btn.getAttribute('data-chain-name');
                var statusEl  = btn.parentElement.querySelector('.bcc-chain-status');

                btn.disabled = true;
                btn.textContent = 'Indexing...';
                if (statusEl) statusEl.textContent = '';

                var body = new FormData();
                body.append('action', 'bcc_chain_refresh');
                body.append('nonce', nonce);
                body.append('chain_id', chainId);

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        btn.disabled = false;
                        btn.textContent = 'Refresh';
                        if (statusEl) {
                            if (resp.success) {
                                statusEl.style.color = '#00a32a';
                                statusEl.textContent = resp.data.message;
                            } else {
                                statusEl.style.color = '#d63638';
                                statusEl.textContent = resp.data.message || 'Error';
                            }
                        }
                    })
                    .catch(function(err) {
                        btn.disabled = false;
                        btn.textContent = 'Refresh';
                        if (statusEl) {
                            statusEl.style.color = '#d63638';
                            statusEl.textContent = 'Network error';
                        }
                    });
            }

            // Per-chain refresh buttons.
            document.querySelectorAll('.bcc-chain-refresh-btn').forEach(function(btn) {
                btn.addEventListener('click', function() { refreshChain(btn); });
            });

            // Refresh All — sequentially to avoid API rate limits.
            var refreshAllBtn = document.getElementById('bcc-refresh-all-chains');
            var refreshAllStatus = document.getElementById('bcc-refresh-all-status');

            if (refreshAllBtn) {
                refreshAllBtn.addEventListener('click', function() {
                    var buttons = Array.from(document.querySelectorAll('.bcc-chain-refresh-btn:not(:disabled)'));
                    var total   = buttons.length;
                    var done    = 0;

                    refreshAllBtn.disabled = true;
                    refreshAllBtn.textContent = 'Refreshing...';
                    if (refreshAllStatus) refreshAllStatus.textContent = '0 / ' + total;

                    function next() {
                        if (buttons.length === 0) {
                            refreshAllBtn.disabled = false;
                            refreshAllBtn.textContent = 'Refresh All Chains';
                            if (refreshAllStatus) refreshAllStatus.textContent = 'Done! ' + done + ' / ' + total + ' completed.';
                            return;
                        }

                        var btn = buttons.shift();
                        var origResolve;
                        var statusEl = btn.parentElement.querySelector('.bcc-chain-status');

                        btn.disabled = true;
                        btn.textContent = 'Indexing...';

                        var body = new FormData();
                        body.append('action', 'bcc_chain_refresh');
                        body.append('nonce', nonce);
                        body.append('chain_id', btn.getAttribute('data-chain-id'));

                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                            .then(function(r) { return r.json(); })
                            .then(function(resp) {
                                btn.disabled = false;
                                btn.textContent = 'Refresh';
                                done++;
                                if (refreshAllStatus) refreshAllStatus.textContent = done + ' / ' + total;
                                if (statusEl) {
                                    statusEl.style.color = resp.success ? '#00a32a' : '#d63638';
                                    statusEl.textContent = resp.success ? resp.data.message : (resp.data.message || 'Error');
                                }
                                next();
                            })
                            .catch(function() {
                                btn.disabled = false;
                                btn.textContent = 'Refresh';
                                done++;
                                if (statusEl) { statusEl.style.color = '#d63638'; statusEl.textContent = 'Network error'; }
                                next();
                            });
                    }

                    next();
                });
            }
        })();
        </script>
        <?php
    }
}
