<?php

namespace App\Billing;

final readonly class Quote
{
    public function __construct(
        public Money $minimumProviderCost,
        public Money $maximumProviderCost,
        public Money $minimumCharge,
        public Money $maximumCharge,
        public TokenCount $text,
        public TokenCount $image,
        public TokenCount $output,
        public TokenCount $reasoning,
    ) {}
}
