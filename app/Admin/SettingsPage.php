<?php

namespace BCC\Onchain\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class SettingsPage
{
    const PAGE_SLUG  = 'bcc-onchain-signals';
    const OPT_GROUP  = 'bcc_onchain_settings';

    public static function register_page(): void
    {
        add_submenu_page(
            'bcc-trust-dashboard',
            'On-Chain Signals',
            'On-Chain Signals',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void
    {
        $key_from_config = defined('BCC_ETHERSCAN_API_KEY');
        ?>
        <div class="wrap">
            <h1>BCC On-Chain Signals</h1>

            <?php if ($key_from_config): ?>
                <div class="notice notice-success inline">
                    <p><strong>BCC_ETHERSCAN_API_KEY</strong> is defined in wp-config.php — Ethereum signals are active.</p>
                </div>
            <?php else: ?>
                <div class="notice notice-warning inline">
                    <p><strong>Ethereum (Etherscan):</strong> No API key configured.
                       Define <code>BCC_ETHERSCAN_API_KEY</code> in <code>wp-config.php</code> to enable Ethereum signals.
                       Get a free key at <a href="https://etherscan.io/myapikey" target="_blank">etherscan.io</a>.
                    </p>
                </div>
            <?php endif; ?>

            <div class="notice notice-info inline">
                <p><strong>Solana:</strong> Uses the public mainnet RPC — no API key required.</p>
            </div>

            <hr>

            <h2>Score Breakdown Reference</h2>
            <p>Per-wallet score maximum: <strong>40 points</strong></p>
            <p>Total on-chain bonus per user: <strong>capped at <?php echo (int) BCC_ONCHAIN_MAX_TOTAL_BONUS; ?> points</strong> regardless of how many wallets are connected.</p>
            <table class="widefat striped" style="max-width:700px">
                <thead>
                    <tr><th>Signal</th><th>Condition</th><th>Points</th></tr>
                </thead>
                <tbody>
                    <tr><td rowspan="6"><strong>Wallet Age</strong> (max <?php echo BCC_ONCHAIN_MAX_AGE_SCORE; ?>)</td>
                        <td>&lt; 180 days</td><td>0.2</td></tr>
                    <tr><td>180 – 364 days</td><td>0.5</td></tr>
                    <tr><td>1 – 2 years</td><td>2</td></tr>
                    <tr><td>2 – 3 years</td><td>3</td></tr>
                    <tr><td>3 – 5 years</td><td>6</td></tr>
                    <tr><td>5+ years</td><td>8 (cap <?php echo BCC_ONCHAIN_MAX_AGE_SCORE; ?>)</td></tr>

                    <tr><td rowspan="5"><strong>Transaction Depth</strong> (max <?php echo BCC_ONCHAIN_MAX_DEPTH_SCORE; ?>)</td>
                        <td>&lt; 20 txs</td><td>0.2</td></tr>
                    <tr><td>20 – 99</td><td>1</td></tr>
                    <tr><td>100 – 499</td><td>3</td></tr>
                    <tr><td>500 – 1,999</td><td>5</td></tr>
                    <tr><td>2,000+</td><td>7 (cap <?php echo BCC_ONCHAIN_MAX_DEPTH_SCORE; ?>)</td></tr>

                    <tr><td rowspan="7"><strong>Contract Deployments</strong> (max <?php echo BCC_ONCHAIN_MAX_CONTRACT_SCORE; ?>)</td>
                        <td>0 contracts</td><td>0.2</td></tr>
                    <tr><td>1 contract</td><td>0.5</td></tr>
                    <tr><td>2 contracts</td><td>1</td></tr>
                    <tr><td>3 contracts</td><td>3</td></tr>
                    <tr><td>5 contracts</td><td>4</td></tr>
                    <tr><td>10 contracts</td><td>5</td></tr>
                    <tr><td>20+ contracts</td><td>8 (cap <?php echo BCC_ONCHAIN_MAX_CONTRACT_SCORE; ?>)</td></tr>
                </tbody>
            </table>

            <h2>Anti-Gaming Multiplier (Contract Score)</h2>
            <p>Applied when contract age data is available:</p>
            <table class="widefat striped" style="max-width:400px">
                <thead>
                    <tr><th>Contract Age</th><th>Multiplier</th></tr>
                </thead>
                <tbody>
                    <tr><td>&lt; 30 days</td><td>× 0.15</td></tr>
                    <tr><td>30 – 90 days</td><td>× 0.30</td></tr>
                    <tr><td>90 – 365 days</td><td>× 0.45</td></tr>
                    <tr><td>1+ year</td><td>× 0.60</td></tr>
                </tbody>
            </table>

            <hr>

            <h2>Manual Refresh</h2>
            <p>Enter a PeepSo page ID to force-refresh its on-chain signals right now (bypasses the 24-hour cache).</p>
            <div id="bcc-onchain-refresh-form" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                <input type="number" id="bcc-onchain-page-id" placeholder="Page ID" class="regular-text" min="1">
                <button class="button button-primary" id="bcc-onchain-refresh-btn">Refresh Now</button>
                <span id="bcc-onchain-refresh-status"></span>
            </div>

            <hr>

            <h2>Cached Signals</h2>
            <p>Signals are re-fetched from the blockchain APIs every <?php echo BCC_ONCHAIN_CACHE_HOURS; ?> hours for active users. The daily cron runs at the time the plugin was activated.</p>

            <hr>

            <?php self::render_indexer_stats(); ?>
            <?php self::render_enrichment_stats(); ?>

        </div>
        <?php
    }

    /**
     * Render the validator indexer metrics panel.
     */
    private static function render_indexer_stats(): void
    {
        $allStats = get_option('bcc_onchain_indexer_stats', []);

        ?>
        <h2>Validator Indexer</h2>
        <?php if (empty($allStats)): ?>
            <p>No indexer runs recorded yet. The indexer runs every 4 hours.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:800px">
                <thead>
                    <tr>
                        <th>Chain</th>
                        <th>Total</th>
                        <th>New</th>
                        <th>Updated</th>
                        <th>Unchanged</th>
                        <th>Refreshed</th>
                        <th>Last Run</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allStats as $slug => $s): ?>
                    <tr>
                        <td><strong><?php echo esc_html($s['chain'] ?? $slug); ?></strong></td>
                        <td><?php echo (int) ($s['total'] ?? 0); ?></td>
                        <td><?php echo (int) ($s['new'] ?? 0); ?></td>
                        <td><?php echo (int) ($s['updated'] ?? 0); ?></td>
                        <td><?php echo (int) ($s['unchanged'] ?? 0); ?></td>
                        <td><?php echo (int) ($s['refreshed'] ?? 0); ?></td>
                        <td><?php echo esc_html($s['timestamp'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            // Totals row
            $totals = ['total' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($allStats as $s) {
                $totals['total']     += (int) ($s['total'] ?? 0);
                $totals['new']       += (int) ($s['new'] ?? 0);
                $totals['updated']   += (int) ($s['updated'] ?? 0);
                $totals['unchanged'] += (int) ($s['unchanged'] ?? 0);
            }
            $writeRate = $totals['total'] > 0
                ? round((($totals['new'] + $totals['updated']) / $totals['total']) * 100, 1)
                : 0;
            ?>
            <p style="margin-top:8px">
                <strong>Write rate:</strong> <?php echo $writeRate; ?>% of validators required a DB write.
                <?php if ($writeRate < 10): ?>
                    <span style="color:#46b450">&#10003; Lean — most validators unchanged.</span>
                <?php elseif ($writeRate > 50): ?>
                    <span style="color:#d63638">&#9888; High churn — investigate if expected.</span>
                <?php endif; ?>
            </p>
        <?php endif;
    }

    /**
     * Render the enrichment scheduler metrics panel.
     */
    private static function render_enrichment_stats(): void
    {
        $stats = get_option('bcc_onchain_enrichment_stats', []);

        ?>
        <hr>
        <h2>Enrichment Scheduler</h2>
        <?php if (empty($stats)): ?>
            <p>No enrichment runs recorded yet. The scheduler runs every hour.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:600px">
                <tbody>
                    <tr><th>Processed</th><td><?php echo (int) ($stats['processed'] ?? 0); ?></td></tr>
                    <tr><th>Failed</th><td><?php echo (int) ($stats['failed'] ?? 0); ?></td></tr>
                    <tr><th>Skipped</th><td><?php echo (int) ($stats['skipped'] ?? 0); ?></td></tr>
                    <tr><th>API Calls Used</th><td><?php echo (int) ($stats['api_calls'] ?? 0); ?> / 200</td></tr>
                    <tr><th>Stop Reason</th><td><code><?php echo esc_html($stats['stopped_reason'] ?? '—'); ?></code></td></tr>
                    <tr><th>Last Run</th><td><?php echo esc_html($stats['timestamp'] ?? '—'); ?></td></tr>
                </tbody>
            </table>
            <?php
            $failed = (int) ($stats['failed'] ?? 0);
            $processed = (int) ($stats['processed'] ?? 0);
            if ($failed > 0 && $processed > 0):
                $failRate = round(($failed / ($processed + $failed)) * 100, 1);
                ?>
                <p style="margin-top:8px">
                    <strong>Failure rate:</strong> <?php echo $failRate; ?>%
                    <?php if ($failRate > 20): ?>
                        <span style="color:#d63638">&#9888; High failure rate — check LCD endpoint health.</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif;
    }
}
