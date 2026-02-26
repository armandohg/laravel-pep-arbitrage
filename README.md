# PEP Arbitrage

A real-time arbitrage monitor for **$PEP (Pepecoin)** built with Laravel 12, Livewire 4, and Flux UI. It compares order books across MEXC, CoinEx, and Kraken, persists detected opportunities to a database, and surfaces them in a live dashboard that auto-refreshes every 5 seconds.

---

## How it works

```
Exchange APIs (MEXC · CoinEx · Kraken)
         │
         ▼
  arbitrage:find           ← Artisan command (polling loop)
         │
         ├── getOrderBook()   ← fetches normalized bids/asks
         ├── getBalances()    ← fetches available USDT / PEP per exchange
         │                       (skipped in --pretend mode)
         ├── balanceCap()     ← min(quote_balance / ask_price, pep_balance_on_sell)
         ├── DetectOpportunity  ← walks order books up to balanceCap, net of fees
         │        └── normalizeToUsdt()  ← converts Kraken USD prices to USDT
         └── ArbitrageOpportunity::fromOpportunityData()  ← persists to DB
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

By default the command fetches your available balances before each poll and caps the detectable volume to what you can actually execute:

```
buy_cap  = quote_balance_on_buy_exchange / top_ask_price
sell_cap = PEP_balance_on_sell_exchange
max_amount = min(buy_cap, sell_cap)
```

The order-book walk stops as soon as `max_amount` of PEP has been filled, so the persisted `amount`, `profit`, and `total_buy_cost` reflect a **real, executable trade** given your current balances — not a theoretical maximum.

If either cap is zero (no funds available on one side), no opportunity is persisted for that direction.

> **API keys required** — balance fetching calls private authenticated endpoints on each exchange. Make sure your keys are set in `.env` before running in balance-aware mode.

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
```

> **Balance-aware mode** calls private authenticated endpoints to fetch balances. Run with `--pretend` if you only want to monitor spreads without configured API keys.

---

## Running the monitor

### Basic usage (runs indefinitely)

```bash
php artisan arbitrage:find
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--interval=N` | `5` | Seconds to sleep between polls |
| `--min-profit=N` | `0.003` | Minimum profit ratio to persist (e.g. `0.003` = 0.3 %) |
| `--once` | — | Run a single poll and exit (useful for testing / cron) |
| `--pretend` | — | Ignore balances — detects the maximum theoretical opportunity |

### Normal mode vs pretend mode

| | Normal | `--pretend` |
|-|--------|-------------|
| Calls `getBalances()` | ✅ yes | ❌ no |
| Volume capped by funds | ✅ yes | ❌ no |
| Results are executable | ✅ yes | simulation only |
| API keys needed | ✅ yes | ❌ no |

Use `--pretend` to explore opportunities without configured API keys, or to see the theoretical maximum that the order books support regardless of your current funds.

### Examples

```bash
# Default: balance-aware, polls every 5 seconds
php artisan arbitrage:find

# Poll every 10 seconds, only record opportunities above 1 %
php artisan arbitrage:find --interval=10 --min-profit=0.01

# Single poll (cron-friendly)
php artisan arbitrage:find --once

# Simulation — ignore balances, show maximum theoretical opportunity
php artisan arbitrage:find --pretend

# Combine: single simulation poll with low threshold
php artisan arbitrage:find --pretend --once --min-profit=0.001
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
    DetectOpportunity.php          # Core profit calculation (walks order books)
    ValueObjects/
      OpportunityData.php          # Immutable result value object
  Console/Commands/
    FindArbitrageCommand.php       # arbitrage:find polling loop
  Exchanges/
    Contracts/ExchangeInterface.php
    BaseExchange.php               # HTTP client, retry with backoff
    Mexc.php
    CoinEx.php
    Kraken.php
  Livewire/
    ArbitrageDashboard.php         # Dashboard component (filter + stats + table)
  Models/
    ArbitrageOpportunity.php       # Eloquent model + fromOpportunityData()
database/
  factories/ArbitrageOpportunityFactory.php
  migrations/*_create_arbitrage_opportunities_table.php
config/
  exchanges.php                    # API credentials + base URLs
```
