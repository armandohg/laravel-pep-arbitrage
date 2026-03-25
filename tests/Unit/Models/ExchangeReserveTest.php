<?php

use App\Models\ExchangeReserve;

test('getFor returns 0 when no record exists', function () {
    expect(ExchangeReserve::getFor('Kraken', 'PEP'))->toBe(0.0);
});

test('getFor returns the stored min_amount', function () {
    ExchangeReserve::create(['exchange' => 'Kraken', 'currency' => 'PEP', 'min_amount' => 4_000_000]);

    expect(ExchangeReserve::getFor('Kraken', 'PEP'))->toBe(4_000_000.0);
});

test('allIndexed returns empty array when no records', function () {
    expect(ExchangeReserve::allIndexed())->toBe([]);
});

test('allIndexed returns reserves keyed by exchange and currency', function () {
    ExchangeReserve::create(['exchange' => 'Kraken', 'currency' => 'PEP', 'min_amount' => 4_000_000]);
    ExchangeReserve::create(['exchange' => 'Mexc', 'currency' => 'USDT', 'min_amount' => 500]);

    $indexed = ExchangeReserve::allIndexed();

    expect($indexed['Kraken']['PEP'])->toBe(4_000_000.0)
        ->and($indexed['Mexc']['USDT'])->toBe(500.0)
        ->and($indexed['Kraken']['USDT'] ?? null)->toBeNull();
});
