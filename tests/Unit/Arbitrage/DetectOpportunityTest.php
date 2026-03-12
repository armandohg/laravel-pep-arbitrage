<?php

use App\Arbitrage\DetectOpportunity;
use App\Arbitrage\ValueObjects\OpportunityData;
use App\Exchanges\Contracts\ExchangeInterface;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function fakeExchange(string $name, float $fee): ExchangeInterface
{
    return new class($name, $fee) implements ExchangeInterface
    {
        public function __construct(
            private readonly string $name,
            private readonly float $fee,
        ) {}

        public function getName(): string
        {
            return $this->name;
        }

        public function getTxFee(): float
        {
            return $this->fee;
        }

        public function getOrderBook(string $symbol): array
        {
            return ['bids' => [], 'asks' => []];
        }

        public function getBalances(): array
        {
            return [];
        }

        public function placeOrder(string $symbol, string $side, float $amount, string $type, ?float $price = null): array
        {
            return [];
        }

        public function withdraw(string $currency, float $amount, string $address, string $network, ?string $withdrawKey = null): array
        {
            return [];
        }

        public function getAvailableMarkets(): array
        {
            return [];
        }

        public function getWithdrawalStatus(string $withdrawalId, ?string $currency = null): array
        {
            return ['status' => 'pending', 'tx_hash' => null];
        }

        public function getDepositStatus(string $txHash): array
        {
            return ['status' => 'pending', 'amount' => null];
        }
    };
}

function book(array $bids, array $asks): array
{
    return ['bids' => $bids, 'asks' => $asks];
}

function level(float $price, float $amount): array
{
    return ['price' => $price, 'amount' => $amount];
}

// ---------------------------------------------------------------------------
// OpportunityData::profitLevel()
// ---------------------------------------------------------------------------

it('returns Low profit level below 1%', function () {
    expect(OpportunityData::profitLevel(0.005))->toBe('Low');
});

it('returns Medium profit level at 1%', function () {
    expect(OpportunityData::profitLevel(0.01))->toBe('Medium');
});

it('returns High profit level at 3%', function () {
    expect(OpportunityData::profitLevel(0.03))->toBe('High');
});

it('returns VeryHigh profit level at 5%', function () {
    expect(OpportunityData::profitLevel(0.05))->toBe('VeryHigh');
});

it('returns Extreme profit level at 10%', function () {
    expect(OpportunityData::profitLevel(0.10))->toBe('Extreme');
});

// ---------------------------------------------------------------------------
// detect() — null cases
// ---------------------------------------------------------------------------

it('returns null when sell net revenue is not greater than buy net cost', function () {
    $detector = new DetectOpportunity;
    $buyExchange = fakeExchange('Mexc', 0.002);
    $sellExchange = fakeExchange('CoinEx', 0.002);

    // ask 1.010, fee 0.2% → cost/unit = 1.0120
    // bid 1.000, fee 0.2% → revenue/unit = 0.9980
    $buyBook = book([], [level(1.010, 1000.0)]);
    $sellBook = book([level(1.000, 1000.0)], []);

    expect($detector->detect($buyExchange, $buyBook, $sellExchange, $sellBook))->toBeNull();
});

it('returns null when profit ratio is below minimum threshold', function () {
    $detector = new DetectOpportunity;
    $buyExchange = fakeExchange('Mexc', 0.002);
    $sellExchange = fakeExchange('CoinEx', 0.002);

    // ask 1.000, fee 0.2% → cost/unit = 1.002
    // bid 1.001, fee 0.2% → revenue/unit ≈ 0.998999 → still below cost
    // Use a tiny spread that yields < 0.3%
    // buy at 1.000, sell at 1.001 → gross spread = 0.1%
    // after fees (0.2% each side), net is negative
    $buyBook = book([], [level(1.000, 1000.0)]);
    $sellBook = book([level(1.001, 1000.0)], []);

    expect($detector->detect($buyExchange, $buyBook, $sellExchange, $sellBook, 0.003))->toBeNull();
});

it('returns null when order books are empty', function () {
    $detector = new DetectOpportunity;
    $exchange = fakeExchange('Mexc', 0.002);

    expect($detector->detect($exchange, book([], []), $exchange, book([], [])))->toBeNull();
});

// ---------------------------------------------------------------------------
// detect() — profitable cases
// ---------------------------------------------------------------------------

it('returns correct OpportunityData for a single depth level', function () {
    $detector = new DetectOpportunity;
    $buyExchange = fakeExchange('Mexc', 0.002);
    $sellExchange = fakeExchange('CoinEx', 0.002);

    // buy at 1.000 with 0.2% fee → cost/unit = 1.002
    // sell at 1.020 with 0.2% fee → revenue/unit = 1.0180 (approx)
    $amount = 500.0;
    $buyBook = book([], [level(1.000, $amount)]);
    $sellBook = book([level(1.020, $amount)], []);

    $result = $detector->detect($buyExchange, $buyBook, $sellExchange, $sellBook, 0.003);

    expect($result)->toBeInstanceOf(OpportunityData::class);
    expect($result->buyExchange)->toBe('Mexc');
    expect($result->sellExchange)->toBe('CoinEx');
    expect($result->amount)->toBe($amount);

    $expectedBuyCost = 1.000 * $amount * 1.002;
    $expectedSellRevenue = 1.020 * $amount * 0.998;
    $expectedProfit = $expectedSellRevenue - $expectedBuyCost;
    $expectedRatio = $expectedProfit / $expectedBuyCost;

    expect($result->totalBuyCost)->toBeCloseTo($expectedBuyCost, 6);
    expect($result->totalSellRevenue)->toBeCloseTo($expectedSellRevenue, 6);
    expect($result->profit)->toBeCloseTo($expectedProfit, 6);
    expect($result->profitRatio)->toBeCloseTo($expectedRatio, 6);
    expect($result->profitLevel)->toBe(OpportunityData::profitLevel($expectedRatio));
});

it('accumulates across multiple depth levels', function () {
    $detector = new DetectOpportunity;
    $buyExchange = fakeExchange('Mexc', 0.0);
    $sellExchange = fakeExchange('CoinEx', 0.0);

    // Zero fees for simpler math
    // Level 1: buy 100 @ 1.00, sell 100 @ 1.05
    // Level 2: buy 200 @ 1.01, sell 200 @ 1.06
    $buyBook = book([], [
        level(1.00, 100.0),
        level(1.01, 200.0),
    ]);
    $sellBook = book([
        level(1.06, 200.0),
        level(1.05, 100.0),
    ], []);

    $result = $detector->detect($buyExchange, $buyBook, $sellExchange, $sellBook, 0.0);

    expect($result)->not->toBeNull();
    expect($result->amount)->toBe(300.0);

    $expectedBuyCost = (1.00 * 100.0) + (1.01 * 200.0);
    $expectedSellRevenue = (1.06 * 200.0) + (1.05 * 100.0);

    expect($result->totalBuyCost)->toBeCloseTo($expectedBuyCost, 6);
    expect($result->totalSellRevenue)->toBeCloseTo($expectedSellRevenue, 6);
});

it('caps the filled amount by quoteBalance (buy side)', function () {
    $detector = new DetectOpportunity;
    $buyExchange = fakeExchange('Mexc', 0.0);
    $sellExchange = fakeExchange('CoinEx', 0.0);

    // Order book has 1000 available but quote balance only allows buying 200 PEP at price 1.00
    $buyBook = book([], [level(1.00, 1000.0)]);
    $sellBook = book([level(1.10, 1000.0)], []);

    // quoteBalance = 200 USDT → 0.5% buffer applied → 199.0 USDT effective → 199 PEP buyable at 1.00
    $result = $detector->detect($buyExchange, $buyBook, $sellExchange, $sellBook, 0.0, 200.0, null);

    expect($result)->not->toBeNull();
    expect($result->amount)->toBe(199.0);
    expect($result->totalBuyCost)->toBeCloseTo(199.0, 6);
    expect($result->totalSellRevenue)->toBeCloseTo(218.9, 6);
});

it('returns null when quoteBalance is zero', function () {
    $detector = new DetectOpportunity;
    $buyExchange = fakeExchange('Mexc', 0.0);
    $sellExchange = fakeExchange('CoinEx', 0.0);

    $buyBook = book([], [level(1.00, 1000.0)]);
    $sellBook = book([level(1.10, 1000.0)], []);

    // quoteBalance = 0 → getMaxBuyableAsks returns [] → no opportunity
    expect($detector->detect($buyExchange, $buyBook, $sellExchange, $sellBook, 0.0, 0.0, null))->toBeNull();
});

it('stops accumulating when a deeper level becomes unprofitable', function () {
    $detector = new DetectOpportunity;
    $buyExchange = fakeExchange('Mexc', 0.0);
    $sellExchange = fakeExchange('CoinEx', 0.0);

    // Level 1: buy 100 @ 1.00, sell @ 1.05 → profitable
    // Level 2: buy 100 @ 1.10, sell @ 1.05 → unprofitable (ask > bid)
    $buyBook = book([], [
        level(1.00, 100.0),
        level(1.10, 100.0),
    ]);
    $sellBook = book([
        level(1.05, 200.0),
    ], []);

    $result = $detector->detect($buyExchange, $buyBook, $sellExchange, $sellBook, 0.0);

    expect($result)->not->toBeNull();
    expect($result->amount)->toBe(100.0);
    expect($result->totalBuyCost)->toBeCloseTo(100.0, 6);
    expect($result->totalSellRevenue)->toBeCloseTo(105.0, 6);
});

// ---------------------------------------------------------------------------
// between()
// ---------------------------------------------------------------------------

it('returns the higher-profit direction when both directions are profitable', function () {
    $detector = new DetectOpportunity;
    $exchangeA = fakeExchange('Mexc', 0.0);
    $exchangeB = fakeExchange('CoinEx', 0.0);

    // A→B: buy on A at 1.00, sell on B at 1.10 → 10% profit
    // B→A: buy on B at 1.00, sell on A at 1.05 → 5% profit
    $bookA = book(
        bids: [level(1.05, 1000.0)],
        asks: [level(1.00, 1000.0)],
    );
    $bookB = book(
        bids: [level(1.10, 1000.0)],
        asks: [level(1.00, 1000.0)],
    );

    $result = $detector->between($exchangeA, $bookA, $exchangeB, $bookB, 0.0);

    expect($result)->not->toBeNull();
    expect($result->buyExchange)->toBe('Mexc');
    expect($result->sellExchange)->toBe('CoinEx');
    expect($result->profitRatio)->toBeCloseTo(0.10, 6);
});

it('returns null when neither direction is profitable', function () {
    $detector = new DetectOpportunity;
    $exchangeA = fakeExchange('Mexc', 0.002);
    $exchangeB = fakeExchange('CoinEx', 0.002);

    // Prices equal → no arbitrage after fees
    $bookA = book(bids: [level(1.00, 1000.0)], asks: [level(1.00, 1000.0)]);
    $bookB = book(bids: [level(1.00, 1000.0)], asks: [level(1.00, 1000.0)]);

    expect($detector->between($exchangeA, $bookA, $exchangeB, $bookB))->toBeNull();
});

// ---------------------------------------------------------------------------
// normalizeToUsdt()
// ---------------------------------------------------------------------------

it('divides prices by usdtUsdRate and leaves amounts unchanged', function () {
    $usdtUsdRate = 0.999;

    $usdBook = book(
        bids: [level(1.020, 1000.0), level(1.010, 2000.0)],
        asks: [level(1.030, 500.0)],
    );

    $normalized = DetectOpportunity::normalizeToUsdt($usdBook, $usdtUsdRate);

    expect($normalized['bids'][0]['price'])->toBeCloseTo(1.020 / $usdtUsdRate, 8);
    expect($normalized['bids'][0]['amount'])->toBe(1000.0);
    expect($normalized['bids'][1]['price'])->toBeCloseTo(1.010 / $usdtUsdRate, 8);
    expect($normalized['bids'][1]['amount'])->toBe(2000.0);
    expect($normalized['asks'][0]['price'])->toBeCloseTo(1.030 / $usdtUsdRate, 8);
    expect($normalized['asks'][0]['amount'])->toBe(500.0);
});
