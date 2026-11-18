<?php

namespace App\Billing;

use InvalidArgumentException;

final readonly class Decimal
{
    private function __construct(
        private int $numerator,
        private int $scale,
    ) {}

    public static function parse(string $value): self
    {
        $value = trim($value);

        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,9}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException("[{$value}] is not a decimal amount.");
        }

        $fraction = $matches[2] ?? '';
        $scale = 10 ** strlen($fraction);

        return new self((int) ($matches[1].$fraction), $scale);
    }

    public function numerator(): int
    {
        return $this->numerator;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    /**
     * Scale the decimal to a fixed number of fractional digits.
     * Extra precision rounds up so a stored amount never understates the parsed value.
     */
    public function toScale(int $places): int
    {
        if ($places < 0 || $places > 9) {
            throw new InvalidArgumentException('Scale must be between 0 and 9 decimal places.');
        }

        $target = 10 ** $places;

        if ($this->scale <= $target) {
            return $this->numerator * intdiv($target, $this->scale);
        }

        $factor = intdiv($this->scale, $target);
        $scaled = intdiv($this->numerator, $factor);

        if ($this->numerator % $factor !== 0) {
            $scaled++;
        }

        return $scaled;
    }
}
