# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# PHP syntax check
php -l app/Fetchers/CosmosFetcher.php

# Check all app/ files
for f in $(find app -name '*.php'); do php -l "$f"; done

# Regenerate optimized classmap
composer dump-autoload -o
```

No tests, linters, or build pipelines. Assets served raw from `assets/`.

## Architecture

WordPress plugin for blockchain data ingestion: fetches validator, NFT collection, and wallet signal data from 6 chain types, stores it, and provides trust score bonuses via the bcc-trust-engine.

### Plugin Ecosystem

- **bcc-core** (required) — ServiceLocator, contracts, DB helpers, logging
- **bcc-trust-engine** (consumer) — receives `onchain_bonus` via `ScoreContributorInterface`

### Namespace & Autoloading

Namespace root: `BCC\Onchain\` mapped to `app/` via Composer PSR-4 + optimized classmap.

### Chain Fetchers

Each fetcher implements `FetcherInterface` and wraps chain-specific APIs:

| Fetcher | Chain Type | API | Key Methods |
|---------|-----------|-----|-------------|
| `CosmosFetcher` | cosmos | LCD REST | `fetch_validator`, `enrich_validator`, `fetch_all_validators` |
| `EvmFetcher` | evm | Etherscan + Reservoir | `fetch_collections`, `fetch_top_collections` |
| `SolanaFetcher` | solana | JSON-RPC | `fetch_validator`, `fetch_collections` (DAS) |
| `ThorchainFetcher` | thorchain | THORNode API | `fetch_validator`, `fetch_all_validators` |
| `PolkadotFetcher` | polkadot | Subscan REST | `fetch_validator`, `fetch_all_validators` |
| `NearFetcher` | near | JSON-RPC | `fetch_validator`, `fetch_all_validators` |

`FetcherFactory::make_for_chain()` resolves chain_type → driver class.

### API Resilience Layer

**All external HTTP calls go through `ApiRetry`** — no raw `wp_remote_get`/`post` in app code.

`ApiRetry::get()` / `ApiRetry::post()`:
- Max 3 retries with exponential backoff (2s → 5s → 12.5s, capped at 30s)
- Retries on: timeouts, network errors (WP_Error), 5xx, 429
- Does NOT retry on: 4xx (except 429)
- 429: parses `Retry-After` header, falls back to 15s
- Integrates with `CircuitBreaker` per chain_id

`CircuitBreaker`:
- Per-chain failure tracking (Redis-backed wp_cache + transient fallback)
- OPEN after 5 consecutive failures → blocks all requests for 5 minutes
- HALF-OPEN after cooldown → allows one probe request
- CLOSED on success → resets failure counter

### Enrichment Scheduler

`EnrichmentScheduler::run()` — hourly cron, DB-driven:
- Priority: linked validators > missing data > high stake > stale
- Budget: 200 API calls global, 50 per chain per run
- Backoff: 15m → 1h → 4h → 16h → 24h (max 10 attempts)
- Locking: Redis-based (wp_cache_add), 10min TTL with heartbeat

**API budget is tracked AFTER successful response** (not before). `trackApiCall()` is called by `CosmosFetcher::lcdGet()` only on HTTP 200.

### Data Flow

1. **Index** (every 4h): `ChainRefreshService::index_validators()` → `fetcher->fetch_all_validators()` → `ValidatorRepository::bulkUpsert()` (lean write: skip unchanged, batch-update stale)
2. **Enrich** (hourly): `EnrichmentScheduler::run()` → `fetcher->enrich_validator()` → `ValidatorRepository::enrichByOperator()`
3. **Signals** (daily): `SignalRefreshService::dailyRefresh()` → `SignalFetcher::fetch()` → `SignalRepository::upsert()` → `BonusService::applyBonus()`
4. **Seed** (on wallet verify): `WalletSeedService::onWalletVerified()` — immediate fetch for new wallets

### Cron Jobs

| Hook | Interval | Handler |
|------|----------|---------|
| `bcc_refresh_validators` | hourly | `EnrichmentScheduler::run()` |
| `bcc_index_validators` | 4h | `ChainRefreshService::index_validators()` |
| `bcc_index_collections` | 4h | `ChainRefreshService::index_collections()` |
| `bcc_refresh_collections` | 4h | `ChainRefreshService::refresh_collections()` |
| `bcc_onchain_daily_refresh` | daily | `SignalRefreshService::dailyRefresh()` |
| `bcc_onchain_retry_bonus` | hourly | `BonusRetryService::processAll()` |

### Database Tables

All prefixed via `$wpdb->prefix`:
- `bcc_chains` — chain metadata (RPC URLs, decimals, bech32_prefix)
- `bcc_onchain_validators` — validator data with enrichment scheduling columns (next_enrichment_at, retry_after, enrichment_attempts)
- `bcc_onchain_signals` — wallet on-chain signals (age, tx count, contracts)
- `bcc_onchain_collections` — NFT collection data
- `bcc_onchain_claims` — validator/collection claims by users
- `bcc_wallet_links` — user wallet verifications

### Bonus Application

`BonusService::applyBonus()` → `ScoreContributorInterface::applyBonus()` (bcc-trust-engine).
If trust engine unavailable → `BonusRetryService::queue()` → retried hourly (max 5 attempts).
Cap: `BCC_ONCHAIN_MAX_TOTAL_BONUS` (40 points).

### Required wp-config.php Constants

- `BCC_ETHERSCAN_API_KEY` — Etherscan (free tier)
- `BCC_SUBSCAN_API_KEY` — Polkadot/Subscan (free tier)
- `BCC_RESERVOIR_API_KEY` — Optional, Reservoir (free tier works without it)

## Conventions

- Static classes for stateless services; FetcherFactory resolves chain_type → instance
- All HTTP via `ApiRetry::get()`/`post()` — never raw `wp_remote_*` in app code
- `EnrichmentScheduler::trackApiCall()` called AFTER successful response only
- WordPress native cron (not Action Scheduler)
- `BCC\Core\Log\Logger` for all logging
- `ChainRepository::getActive($type)` for chain queries (cached)
