<?php

use App\Actions\Billing\CalculateCost;
use App\Billing\Rates;
use App\Billing\TokenUsage;

test('bills the succeeded generation from its own rates', function () {
    $cost = (new CalculateCost)(
        new TokenUsage(12, 765, 6450, 3184),
        publishedPixelforgeRates(),
    );

    expect($cost->provider->nanodollars())->toBe(305_510_000)
        ->and($cost->provider->usd())->toBe('0.305510000')
        ->and($cost->charge->nanodollars())->toBe(381_887_500)
        ->and($cost->charge->usd())->toBe('0.381887500');
});

test('bills zero usage as free', function () {
    $cost = (new CalculateCost)(
        new TokenUsage(0, 0, 0, 0),
        publishedPixelforgeRates(),
    );

    expect($cost->provider->nanodollars())->toBe(0)
        ->and($cost->charge->nanodollars())->toBe(0);
});

test('uses the rates it is given', function () {
    $cost = (new CalculateCost)(
        new TokenUsage(12, 765, 6450, 3184),
        Rates::perMillion('1.00', '1.00', '1.00', '1.00'),
    );

    expect($cost->provider->nanodollars())->toBe(10_411_000)
        ->and($cost->charge->nanodollars())->toBe(13_013_750);
});

test('rounds a fractional token cost and markup up', function () {
    $cost = (new CalculateCost)(
        new TokenUsage(1, 0, 0, 0),
        Rates::perMillion('0.0001', '0', '0', '0'),
    );

    expect($cost->provider->nanodollars())->toBe(1)
        ->and($cost->charge->nanodollars())->toBe(2);
});

function publishedPixelforgeRates(): Rates
{
    return Rates::perMillion('5.00', '10.00', '40.00', '12.50');
}
