# PEP Arbitrage

A real-time arbitrage monitor for **$PEP (Pepecoin)** built with Laravel 12, Livewire 4, and Flux UI. It compares order books across MEXC, CoinEx, and Kraken, persists detected opportunities to a database, surfaces them in a live dashboard that auto-refreshes every 5 seconds, executes real orders when confirmed, and includes an automated rebalancing system that distributes funds across exchanges using DB-backed transfer routes.

---

## How it works

```
Exchange APIs (MEXC · CoinEx · Kraken)
         │
         ▼
  arbitrage:find                ← Artisan command (polling loop)
         │
         ├── Phase 1 — DISCOVERY
         │        ├── getOrderBook()        ← fetches normalized bids/asks
         │        ├── getBalances()         ← fetches available USDT / PEP per exchange
         │        │                            (skipped in --pretend mode)
         │        └── DetectOpportunity
         │                 ├── normalizeToUsdt()       ← converts Kraken USD prices to USDT
         │                 ├── getMaxBuyableAsks()     ← level-by-level buy capacity from quote balance
         │                 ├── getMaxSellableBids()    ← level-by-level sell capacity from PEP balance
         │                 └── buyLevels × sellLevels  ← tries all depth combinations, picks best profit
         │
         ├── Phase 2 — SUSTAIN (re-checks the winning pair every N seconds)
         │        └── Confirms profit is stable within ±tolerance% for the sustain window
         │
         └── Phase 3 — EXECUTION  (only with --execute, skipped if --pretend)
                  └── ExecuteArbitrage
                           ├── placeOrder(buy)   ← limit or market, exchange-specific API
                           ├── placeOrder(sell)  ← if buy OK; Log::critical if sell fails
                           └── ArbitrageOpportunity::recordExecution()  ← updates DB record
                  │
                  ▼
          arbitrage_opportunities  (MySQL / SQLite)
                  │
                  ▼
       /dashboard  ← Livewire component, wire:poll.5s
```

### Exchange pairs monitored

| Pair | Buy exchange | Sell exchange | Quote |
|------|-------------|---------------|-------|
| PEP/USDT | MEXC | CoinEx | USDT |
| PEP/USDT | CoinEx | MEXC | USDT |
| PEP/USDT | MEXC | Kraken\* | USDT |
| PEP/USDT | Kraken\* | MEXC | USDT |
| PEP/USDT | CoinEx | Kraken\* | USDT |
| PEP/USDT | Kraken\* | CoinEx | USDT |

\* Kraken quotes PEP in USD. The monitor fetches the USDT/USD rate from Kraken and normalises Kraken's prices to USDT before comparing.

### Profit levels

| Label | Profit ratio |
|-------|-------------|
| Low | < 1 % |
| Medium | 1 – 3 % |
| High | 3 – 5 % |
| VeryHigh | 5 – 10 % |
| Extreme | > 10 % |

Only opportunities above the configurable minimum threshold (default **0.3 %**) are persisted.

### Exchange fees used in calculations

| Exchange | Taker fee |
|----------|-----------|
| MEXC | 0.05 % |
| CoinEx | 0.20 % |
| Kraken | 0.26 % |

Profit is calculated **net of fees on both sides**:

```
profit_ratio = (sell_revenue - buy_cost) / buy_cost
```

### Balance-aware detection

By default the command fetches your available balances before each poll and builds level-by-level capacity arrays before searching for the best opportunity:

```
getMaxBuyableAsks  — for each ask level: how many PEP can be bought
                     with the remaining quote balance (USDT / USD)
getMaxSellableBids — for each bid level: how many PEP can be sold
                     with the remaining PEP balance
```

`DetectOpportunity` then tries every combination of `1..5` depth levels on the buy side against `1..5` depth levels on the sell side (up to 25 combinations per direction) and picks the one that yields the **highest absolute profit** above the minimum threshold.

This means the persisted `amount`, `profit`, and `total_buy_cost` reflect a **real, executable trade** across potentially multiple price levels, given your current balances — not a theoretical maximum.

If the available balance on either side is zero, no opportunity is persisted for that direction.

> **API keys required** — balance fetching calls private authenticated endpoints on each exchange. Make sure your keys are set in `.env` before running in balance-aware mode.

---

## Rebalancing

The rebalancing system distributes PEP and USDT evenly across all three exchanges. It computes the ideal target (total ÷ 3 per exchange) and generates the minimum set of transfers needed to reach it, then executes withdrawals through exchange APIs.

### How transfer routes work

Routes are stored in the database, not in config. Before rebalancing, you must sync networks and wallets from the exchanges:

```bash
# Fetch withdrawal networks and deposit addresses, build all transfer routes
php artisan exchanges:sync-networks
```

This command:
1. Calls each exchange's API to discover available withdrawal networks and their fees
2. Fetches deposit addresses for each asset/network combination
3. Seeds Kraken routes from `config/exchanges.php` (Kraken has no sync API)
4. Builds `transfer_routes` records for every valid from→to permutation

Canonical network codes used internally:

| Exchange-specific | Canonical |
|---|---|
| PEP, PEPCHAIN | `PEP` |
| TRX, TRC20, trc20 | `TRC20` |
| ETH, ERC20, erc20 | `ERC20` |
| BEP20, BSC, bsc | `BSC` |

### Rebalance command

```bash
# Dry-run (default) — shows plan without executing
php artisan exchanges:rebalance

# Execute transfers
php artisan exchanges:rebalance --execute

# Force a specific network for all transfers
php artisan exchanges:rebalance --network=TRC20

# Adjust the tolerance threshold (default 10%)
php artisan exchanges:rebalance --tolerance=0.05

# Combine options
php artisan exchanges:rebalance --network=PEP --execute
```

### Rebalance options

| Option | Default | Description |
|--------|---------|-------------|
| `--tolerance=N` | `0.10` | Max allowed deviation per exchange before rebalancing (e.g. `0.10` = 10 %) |
| `--network=CODE` | — | Force a specific network for all transfers (e.g. `TRC20`, `PEP`, `ERC20`) |
| `--execute` | — | Execute the transfers (default is dry-run) |

### Dry-run output

```
Balance rebalance plan [DRY-RUN — use --execute to apply]

Current state:
+----------+-------------+------------------+
| Exchange | PEP         | USDT-equiv       |
+----------+-------------+------------------+
| Mexc     | 5,000,000   | 500.00 USDT      |
| CoinEx   | 1,000,000   | 100.00 USDT      |
| Kraken   | 1,000,000   | 100.00 USDT      |
+----------+-------------+------------------+

Target per exchange: 2,333,333 PEP  |  233.33 USDT

Transfers needed:
+---+----------+------------------+------------+---------+------------------+--------+
| # | Currency | Route            | Amount     | Network | Destination      | Notes  |
+---+----------+------------------+------------+---------+------------------+--------+
| 1 | PEP      | Mexc → CoinEx   | 1,333,333  | [PEP]   | TXabc…def123     |        |
| 2 | PEP      | Mexc → Kraken   | 1,333,334  | [PEP]   | kraken_key_name  |        |
| 3 | USDT     | Mexc → CoinEx   | 133.33     | [TRC20] | TXxyz…789        |        |
+---+----------+------------------+------------+---------+------------------+--------+
```

### Rebalance architecture

```
exchanges:sync-networks
         │
         ├── Mexc::getWithdrawalNetworks()   ← GET /api/v3/capital/config/getall
         ├── CoinEx::getWithdrawalNetworks() ← GET /v2/assets/all-deposit-withdraw-config
         ├── Mexc::getDepositAddress()        ← GET /api/v3/capital/deposit/address
         ├── CoinEx::getDepositAddress()      ← GET /v2/assets/deposit-address
         │
         ├── upsert exchange_networks   ← fee, limits, deposit/withdraw flags
         ├── upsert exchange_wallets    ← deposit addresses per exchange/asset/network
         └── upsert transfer_routes     ← from→to permutations, FK to wallet

exchanges:rebalance
         │
         ├── getBalances() × 3 exchanges
         ├── getOrderBook('usdt_usd')   ← USDT/USD normalization rate
         ├── compute surpluses & deficits per currency
         ├── TransferRouteService::getRouteForTransfer()
         │        └── queries transfer_routes (cheapest fee, or forced network)
         │             eagerly loads exchange_wallets (address, memo)
         │             looks up exchange_networks for exchange-specific network_id
         └── execute: Exchange::withdraw(currency, amount, address, networkId)
```

---

## Requirements

- PHP 8.3+
- Composer
- Node.js 20+ / npm
- MySQL or SQLite

---

## Setup

```bash
# 1. Install dependencies
composer install
npm install

# 2. Copy environment file
cp .env.example .env

# 3. Generate app key
php artisan key:generate

# 4. Configure your database in .env, then run migrations
php artisan migrate

# 5. Build frontend assets
npm run build
```

---

## Configuration

Add your exchange API credentials to `.env`:

```ini
# MEXC  — https://www.mexc.com/user/openapi
MEXC_API_KEY=your_mexc_api_key
MEXC_API_SECRET=your_mexc_api_secret

# CoinEx  — https://www.coinex.com/apikey
COINEX_API_KEY=your_coinex_api_key
COINEX_API_SECRET=your_coinex_api_secret

# Kraken  — https://www.kraken.com/u/security/api
KRAKEN_API_KEY=your_kraken_api_key
KRAKEN_API_SECRET=your_kraken_api_secret

# Order execution (optional)
ARBITRAGE_ORDER_TYPE=limit   # 'limit' (default) or 'market'
```

For rebalancing, Kraken's deposit addresses and withdrawal key names (Kraken uses named keys, not raw addresses) must be set in `config/exchanges.php` under `kraken.deposit_addresses` and `kraken.withdraw_keys`. These are seeded into the DB automatically by `exchanges:sync-networks`.

> **Balance-aware mode** calls private authenticated endpoints to fetch balances. Run `arbitrage:find` with `--pretend` if you only want to monitor spreads without configured API keys.

---

## Running the monitor

### Basic usage (runs indefinitely)

```bash
php artisan arbitrage:find
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--interval=N` | `5` | Seconds to sleep between discovery polls |
| `--min-profit=N` | `0.003` | Minimum profit ratio to persist (e.g. `0.003` = 0.3 %) |
| `--min-amount=N` | `0` | Minimum trade size in USDT to consider (e.g. `5` = $5 minimum) |
| `--sustain=N` | `10` | Seconds the winning pair must hold steady before executing |
| `--sustain-interval=N` | `2` | Seconds between checks during the sustain phase |
| `--stability=N` | `0.5` | Max allowed profit drift during sustain (e.g. `0.5` = ±0.5 %) |
| `--once` | — | Run a single discovery poll and exit (useful for testing / cron) |
| `--pretend` | — | Ignore balances — detects the maximum theoretical opportunity, never places orders |
| `--execute` | — | Place real orders when an opportunity is confirmed after the sustain phase |

### Execution modes

| | `--pretend` | Default (no flags) | `--execute` |
|-|-------------|-------------------|-------------|
| Calls `getBalances()` | ❌ no | ✅ yes | ✅ yes |
| Volume capped by funds | ❌ no | ✅ yes | ✅ yes |
| Sustain phase | ✅ yes | ✅ yes | ✅ yes |
| Places real orders | ❌ never | ❌ no (warns to use `--execute`) | ✅ yes |
| API keys needed | ❌ no | ✅ yes | ✅ yes |

Use `--pretend` to explore opportunities without configured API keys. Omit `--execute` to monitor and record opportunities without acting on them.

### Examples

```bash
# Default: balance-aware, polls every 5s, records opportunities but does not trade
php artisan arbitrage:find

# Poll every 10 seconds, only record opportunities above 1 %
php artisan arbitrage:find --interval=10 --min-profit=0.01

# Single poll (cron-friendly)
php artisan arbitrage:find --once

# Simulation — ignore balances, show maximum theoretical opportunity
php artisan arbitrage:find --pretend

# Combine: single simulation poll with low threshold
php artisan arbitrage:find --pretend --once --min-profit=0.001

# Live trading — execute orders after sustain confirmation
php artisan arbitrage:find --execute

# Live trading with custom sustain window and min trade size
php artisan arbitrage:find --execute --sustain=15 --min-amount=10
```

### Output

```
# Normal mode
PEP arbitrage monitor | interval: 5s | min-profit: 0.30%
[14:32:01] No opportunities above 0.30%
[14:32:06] OPPORTUNITY Mexc → CoinEx [Medium]
           ├ PEP amount : 12,400 PEP
           ├ Cost       : 12.4000 USDT  (required on Mexc)
           ├ Revenue    : 12.5804 USDT  (received from CoinEx)
           └ Profit     : +0.1804 USDT  (+1.4521%)
[14:32:11] No opportunities above 0.30%

# Pretend mode (same spread, no balance cap)
PEP arbitrage monitor | interval: 5s | min-profit: 0.30% | pretend mode (balances ignored)
[14:32:01] OPPORTUNITY Mexc → CoinEx [Medium]
           ├ PEP amount : 2,500,000 PEP
           ├ Cost       : 2,500.0000 USDT  (required on Mexc)
           ├ Revenue    : 2,536.3025 USDT  (received from CoinEx)
           └ Profit     : +36.3025 USDT  (+1.4521%)
```

Press `Ctrl+C` to stop gracefully (SIGINT / SIGTERM handled).

### Running as a background process

```bash
# Using nohup
nohup php artisan arbitrage:find --interval=5 >> storage/logs/arbitrage.log 2>&1 &

# Using Laravel Sail
sail artisan arbitrage:find

# Or schedule a single-poll via cron in routes/console.php
Schedule::command('arbitrage:find --once')->everyMinute();
```

---

## Dashboard

Start the development server:

```bash
composer run dev
# or
php artisan serve & npm run dev
```

Then open `http://laravel-pep-arbitrage.test/dashboard` (or your configured `APP_URL/dashboard`).

The dashboard requires a registered account. Register at `/register`.

### Dashboard features

- **Exchange balances** — cards showing all available balances per exchange (PEP, USDT, USD, etc.) plus a total summary card across all three exchanges; refreshes every 30 seconds or on demand
- **Live stats** — total opportunities detected, today's count, best profit ratio seen
- **Auto-refresh** — the table polls the server every 5 seconds via Livewire
- **Profit-level filter** — click any level button (All / Low / Medium / High / VeryHigh / Extreme) to narrow the table
- **Paginated table** — 20 rows per page, sorted newest first
- **Color-coded badges** — each profit level has a distinct colour for quick scanning

---

## Development

### Run tests

```bash
php artisan test
```

### Run a specific test file or filter

```bash
php artisan test --compact --filter=ArbitrageDashboard
php artisan test --compact --filter=Rebalance
php artisan test --compact --filter=SyncNetworks
php artisan test --compact tests/Feature/FindArbitrageCommandTest.php
```

### Code style

The project uses Laravel Pint (PSR-12 + Laravel preset):

```bash
vendor/bin/pint
```

---

## Project structure

```
app/
  Arbitrage/
    DetectOpportunity.php              # Core profit calculation (walks order books)
    ExecuteArbitrage.php               # Places buy + sell orders; Log::critical on partial failure
    ValueObjects/
      OpportunityData.php              # Immutable opportunity value object
      ExecutionResult.php              # Immutable execution outcome (orderId, failedSide, error)
  Console/Commands/
    FindArbitrageCommand.php           # arbitrage:find — discovery → sustain → execute loop
    ExchangesRebalanceCommand.php      # exchanges:rebalance — plan and execute transfers
    ExchangesSyncNetworksCommand.php   # exchanges:sync-networks — fetch networks/addresses, build routes
  Exchanges/
    Contracts/ExchangeInterface.php    # getOrderBook, getBalances, placeOrder, withdraw, getTxFee
    BaseExchange.php                   # HTTP client, retry with backoff
    Mexc.php
    CoinEx.php
    Kraken.php
  Livewire/
    ArbitrageDashboard.php             # Dashboard component (filter + stats + table)
    ExchangeBalances.php               # Balance cards component (per-exchange + totals, polls 30s)
  Models/
    ArbitrageOpportunity.php           # Eloquent model + fromOpportunityData() + recordExecution()
    ExchangeNetwork.php                # Withdrawal networks per exchange/asset with fees
    ExchangeWallet.php                 # Deposit addresses per exchange/asset/network
    TransferRoute.php                  # Active transfer routes (from→to, FK to wallet)
  Rebalance/
    RebalanceService.php               # Orchestrates balance fetch, plan, and execution
    TransferRouteService.php           # Resolves cheapest (or forced) DB-backed route
    Transfer.php                       # Value object: one transfer (from, to, amount, address…)
    RebalancePlan.php                  # Value object: full plan (states, targets, transfers)
    ExchangeState.php                  # Value object: balances for one exchange
database/
  factories/
    ArbitrageOpportunityFactory.php
    ExchangeNetworkFactory.php
    ExchangeWalletFactory.php
    TransferRouteFactory.php
  migrations/
    *_create_arbitrage_opportunities_table.php
    *_add_execution_columns_to_arbitrage_opportunities_table.php
    *_create_exchange_networks_table.php
    *_create_exchange_wallets_table.php
    *_create_transfer_routes_table.php
config/
  exchanges.php                        # API credentials, base URLs, Kraken withdraw keys
  arbitrage.php                        # ARBITRAGE_ORDER_TYPE ('limit' or 'market')
```
