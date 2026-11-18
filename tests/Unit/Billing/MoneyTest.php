<?php

use App\Billing\Money;

test('parses a nine decimal usd amount into nanodollars', function () {
    $money = Money::fromUsd('0.373875000');

    expect($money->nanodollars())->toBe(373_875_000)
        ->and($money->usd())->toBe('0.373875000');
});

test('parses whole and short usd amounts', function () {
    expect(Money::fromUsd('1')->nanodollars())->toBe(1_000_000_000)
        ->and(Money::fromUsd('5.00')->nanodollars())->toBe(5_000_000_000)
        ->and(Money::fromUsd('12.50')->nanodollars())->toBe(12_500_000_000);
});

test('rejects an amount that is not a decimal', function (string $amount) {
    expect(fn () => Money::fromUsd($amount))->toThrow(InvalidArgumentException::class);
})->with([
    'negative' => ['-1'],
    'blank' => [''],
    'word' => ['abc'],
    'extra dot' => ['5.00.1'],
    'too many decimals' => ['0.0000000001'],
]);

test('adds a markup of twenty five percent', function () {
    $charge = Money::fromNanodollars(373_875_000)->withMarkup();

    expect($charge->nanodollars())->toBe(467_343_750)
        ->and($charge->usd())->toBe('0.467343750');
});

test('rounds a fractional markup up to the next nanodollar', function () {
    expect(Money::fromNanodollars(1)->withMarkup()->nanodollars())->toBe(2);
});

test('labels zero as zero dollars', function () {
    expect(Money::zero()->label())->toBe('$0.00')
        ->and(Money::zero()->exact())->toBe('$0.000000000');
});

test('labels a sub cent amount and keeps the exact value', function () {
    $money = Money::fromNanodollars(575_000);

    expect($money->label())->toBe('< $0.01')
        ->and($money->exact())->toBe('$0.000575000');
});

test('labels one cent with six decimals', function () {
    expect(Money::fromNanodollars(10_000_000)->label())->toBe('$0.010000');
});

test('labels the worked example maximum with six decimals', function () {
    expect(Money::fromNanodollars(373_875_000)->label())->toBe('$0.373875');
});
