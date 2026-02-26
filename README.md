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
         ├── getOrderBook()  ← fetches normalized bids/asks
         ├── DetectOpportunity  ← walks order books, calculates profit net of fees
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

> **Note:** The monitoring command only reads public order book endpoints — API keys are only required for balance queries (not yet implemented in the UI).

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

### Examples

```bash
# Poll every 10 seconds, only record opportunities above 1 %
php artisan arbitrage:find --interval=10 --min-profit=0.01

# Single poll (cron-friendly)
php artisan arbitrage:find --once

# Aggressive polling with low threshold
php artisan arbitrage:find --interval=2 --min-profit=0.001
```

### Output

```
PEP arbitrage monitor | interval: 5s | min-profit: 0.30%
[14:32:01] No opportunities above 0.30%
[14:32:06] OPPORTUNITY Mexc → CoinEx | profit: 1.4521% (Medium) | amount: 2500000 PEP
[14:32:11] No opportunities above 0.30%
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
