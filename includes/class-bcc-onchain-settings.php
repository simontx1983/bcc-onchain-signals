<?php

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Onchain_Settings
{
    const PAGE_SLUG  = 'bcc-onchain-signals';
    const OPT_GROUP  = 'bcc_onchain_settings';

    public static function register_page(): void
    {
        add_submenu_page(
            'bcc-trust-dashboard',         // parent slug (trust engine menu)
            'On-Chain Signals',
            'On-Chain Signals',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting(self::OPT_GROUP, 'bcc_onchain_etherscan_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);
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

        </div>
        <?php
    }
}
