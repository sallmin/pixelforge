<?php

namespace App\Billing;

final readonly class Cost
{
    public function __construct(
        public Money $provider,
        public Money $charge,
    ) {}
}
