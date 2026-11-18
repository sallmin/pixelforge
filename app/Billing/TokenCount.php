<?php

namespace App\Billing;

use InvalidArgumentException;

final readonly class TokenCount
{
    public function __construct(
        public int $minimum,
        public int $maximum,
    ) {
        if ($this->minimum < 0 || $this->maximum < $this->minimum) {
            throw new InvalidArgumentException('Token count range is invalid.');
        }
    }
}
