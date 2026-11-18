<?php

namespace App\Billing;

use InvalidArgumentException;

final readonly class Money
{
    private const int NANODOLLARS_PER_DOLLAR = 1_000_000_000;

    private const int ONE_CENT = 10_000_000;

    private const int MARKUP_NUMERATOR = 5;

    private const int MARKUP_DENOMINATOR = 4;

    private function __construct(private int $nanodollars) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromNanodollars(int $nanodollars): self
    {
        if ($nanodollars < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }

        return new self($nanodollars);
    }

    public static function fromUsd(string $usd): self
    {
        return new self(Decimal::parse($usd)->toScale(9));
    }

    public function nanodollars(): int
    {
        return $this->nanodollars;
    }

    public function plus(self $other): self
    {
        return new self($this->nanodollars + $other->nanodollars);
    }

    /**
     * Add the 25% markup. A fractional nanodollar rounds up, so the charge never drops a partial unit.
     */
    public function withMarkup(): self
    {
        $numerator = $this->nanodollars * self::MARKUP_NUMERATOR;
        $charge = intdiv($numerator, self::MARKUP_DENOMINATOR);

        if ($numerator % self::MARKUP_DENOMINATOR !== 0) {
            $charge++;
        }

        return new self($charge);
    }

    public function usd(): string
    {
        return $this->usdWithDecimals(9);
    }

    public function label(): string
    {
        if ($this->nanodollars === 0) {
            return '$0.00';
        }

        if ($this->nanodollars < self::ONE_CENT) {
            return '< $0.01';
        }

        return '$'.$this->usdWithDecimals(6);
    }

    public function exact(): string
    {
        return '$'.$this->usd();
    }

    private function usdWithDecimals(int $places): string
    {
        $scale = intdiv(self::NANODOLLARS_PER_DOLLAR, 10 ** $places);
        $units = intdiv($this->nanodollars, $scale);
        $remainder = $this->nanodollars % $scale;

        if ($remainder * 2 >= $scale) {
            $units++;
        }

        $divisor = 10 ** $places;
        $whole = intdiv($units, $divisor);
        $fraction = $units % $divisor;

        return $whole.'.'.str_pad((string) $fraction, $places, '0', STR_PAD_LEFT);
    }
}
