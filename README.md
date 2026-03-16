# Blue Collar Crypto – On-Chain Signals

Enriches BCC trust scores with verifiable on-chain data. For each wallet a project owner has connected through the BCC Trust Engine, this plugin fetches wallet age, transaction depth, and smart contract deployment count from the blockchain and converts them into a bonus trust score contribution.

Signals are cached for 24 hours and refreshed daily via WP Cron.

---

## Supported Chains

| Chain | Data Source | API Key Required |
|---|---|---|
| Ethereum | Etherscan API | Yes (free) |
| Solana | Public Mainnet RPC | No |
| Cosmos | Not yet implemented | — |

---

## Setup

### Ethereum (Etherscan)

1. Create a free account at [etherscan.io](https://etherscan.io) and generate an API key.
2. Add the following line to `wp-config.php`:

```php
define('BCC_ETHERSCAN_API_KEY', 'your-api-key-here');
```

Without this key, Ethereum signals will be skipped (Solana still works).

### Solana

No configuration required. The plugin uses the public Solana mainnet RPC endpoint (`api.mainnet-beta.solana.com`).

---

## Score Formula

Each connected wallet produces an on-chain score (max **100 pts** per wallet). The sum of all wallet scores is stored as the `onchain_bonus` on the page's trust score record.

### Wallet Age (max 35 pts)

| Age | Points |
|---|---|
| Less than 90 days | 0 |
| 90 – 364 days | 8 |
| 1 – 2 years | 16 |
| 2 – 3 years | 24 |
| 3 – 5 years | 30 |
| 5+ years | 35 |

### Transaction Depth (max 20 pts)

| Transaction Count | Points |
|---|---|
| Fewer than 10 | 0 |
| 10 – 99 | 5 |
| 100 – 499 | 10 |
| 500 – 1,999 | 15 |
| 2,000+ | 20 |

### Contract Deployments (max 45 pts)

| Contracts | Points Per Contract |
|---|---|
| 0 | 0 |
| 1st and 2nd | +15 each |
| 3rd – 5th | +12 each |
| 6th and above | +10 each (hard cap: 45 total) |

---

## Shortcodes

### `[bcc_onchain_signals]`

Displays the on-chain signals widget for a project page. Shows each connected wallet's chain, a truncated address, wallet age, transaction count, contract count, a score bar for each signal category, and the total bonus contribution.

**Attributes**

| Attribute | Default | Description |
|---|---|---|
| `page_id` | `0` | PeepSo page ID to display signals for. Omit to auto-detect from the current post. |

**Examples**

```
[bcc_onchain_signals]
[bcc_onchain_signals page_id="42"]
```

---

## REST API

### Get signals — `GET /wp-json/bcc/v1/onchain/{page_id}`

Returns the cached on-chain signals for all wallets belonging to the page owner. Public endpoint (no authentication required).

**Response**

```json
[
  {
    "id": "3",
    "user_id": "12",
    "wallet_address": "0xAbC123...",
    "chain": "ethereum",
    "wallet_age_days": "1247",
    "first_tx_at": "2021-06-14 08:22:11",
    "tx_count": "834",
    "contract_count": "2",
    "score_contribution": "61",
    "fetched_at": "2026-03-06 10:00:00"
  }
]
```

### Refresh signals — `POST /wp-json/bcc/v1/onchain/{page_id}/refresh`

Forces an immediate re-fetch from the blockchain APIs for all wallets belonging to the page owner, bypassing the 24-hour cache. Requires admin authentication (`manage_options` capability).

**Response**

```json
{
  "refreshed": 2,
  "signals": [...]
}
```

---

## Admin Settings Page

Found under **BCC Trust → On-Chain Signals** in the WordPress admin.

- Shows whether `BCC_ETHERSCAN_API_KEY` is configured
- Displays the full score breakdown reference table
- Provides a **Manual Refresh** form: enter any page ID and click Refresh Now to force-fetch signals immediately (useful for testing)

---

## Database Tables

### `{prefix}bcc_onchain_signals`

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT | Primary key |
| `user_id` | BIGINT | WP user ID of the wallet owner |
| `wallet_address` | VARCHAR(255) | Blockchain wallet address |
| `chain` | VARCHAR(20) | `ethereum` · `solana` |
| `wallet_age_days` | INT | Days since first transaction |
| `first_tx_at` | DATETIME | Timestamp of first transaction |
| `tx_count` | INT | Total transactions (sent + received, approximated) |
| `contract_count` | INT | Number of smart contracts deployed from this address |
| `score_contribution` | FLOAT | Total bonus score for this wallet (0 – 100) |
| `raw_data` | LONGTEXT | JSON blob of raw API response for debugging |
| `fetched_at` | DATETIME | When the data was last fetched |

Unique index on `(wallet_address, chain)` — one row per wallet per chain.

### `{prefix}bcc_trust_page_scores.onchain_bonus` (added column)

On activation, the plugin adds an `onchain_bonus FLOAT` column to the trust engine's scores table. This stores the sum of all wallet contributions for that page and is updated every time signals are refreshed.

---

## Cron Jobs

| Hook | Schedule | Action |
|---|---|---|
| `bcc_onchain_daily_refresh` | Daily | Finds all users with active wallet verifications and schedules individual page refreshes 10 seconds apart to avoid rate-limiting |
| `bcc_onchain_refresh_page` | Single event | Fetches and stores signals for one page |

---

## Configuration Constants

| Constant | Default | Description |
|---|---|---|
| `BCC_ETHERSCAN_API_KEY` | *(none)* | Define in `wp-config.php` to enable Ethereum signals |
| `BCC_ONCHAIN_CACHE_HOURS` | `24` | Hours before cached signals are considered stale |
| `BCC_ONCHAIN_MAX_AGE_SCORE` | `35` | Maximum points from wallet age |
| `BCC_ONCHAIN_MAX_DEPTH_SCORE` | `20` | Maximum points from transaction depth |
| `BCC_ONCHAIN_MAX_CONTRACT_SCORE` | `45` | Maximum points from contract deployments |
