<?php

use App\Models\ArbitrageSettings;

test('current() returns singleton with correct defaults', function () {
    $settings = ArbitrageSettings::current();

    expect($settings->id)->toBe(1);
    expect($settings->discovery_interval)->toBe(5);
    expect($settings->min_profit_ratio)->toBe(0.003);
    expect($settings->sustain_duration)->toBe(10);
    expect($settings->sustain_interval)->toBe(2);
    expect($settings->stability)->toBe(0.5);
    expect($settings->min_amount)->toBe(0.0);
    expect($settings->execute_orders)->toBeFalse();
});

test('current() returns the same row on repeated calls', function () {
    $first = ArbitrageSettings::current();
    $second = ArbitrageSettings::current();

    expect($second->id)->toBe($first->id);
    expect(ArbitrageSettings::count())->toBe(1);
});

test('casts convert columns to correct types', function () {
    $settings = ArbitrageSettings::current();

    expect($settings->discovery_interval)->toBeInt();
    expect($settings->min_profit_ratio)->toBeFloat();
    expect($settings->sustain_duration)->toBeInt();
    expect($settings->sustain_interval)->toBeInt();
    expect($settings->stability)->toBeFloat();
    expect($settings->min_amount)->toBeFloat();
    expect($settings->execute_orders)->toBeBool();
});
