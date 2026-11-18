<?php

namespace App\Billing;

use InvalidArgumentException;
use OverflowException;

final readonly class Rate
{
    private const int TOKENS_PER_MILLION = 1_000_000;

    private function __construct(private int $nanodollarsPerMillion) {}

    public static function perMillion(string $usd): self
    {
        return new self(Money::fromUsd($usd)->nanodollars());
    }

    /**
     * Price a token count at this rate. A fractional nanodollar rounds up.
     */
    public function cost(int $tokens): Money
    {
        if ($tokens < 0) {
            throw new InvalidArgumentException('Token count cannot be negative.');
        }

        if ($tokens !== 0 && $this->nanodollarsPerMillion > intdiv(PHP_INT_MAX, $tokens)) {
            throw new OverflowException('Token cost exceeds the integer range.');
        }

        $numerator = $tokens * $this->nanodollarsPerMillion;
        $cost = intdiv($numerator, self::TOKENS_PER_MILLION);

        if ($numerator % self::TOKENS_PER_MILLION !== 0) {
            $cost++;
        }

        return Money::fromNanodollars($cost);
    }
}
