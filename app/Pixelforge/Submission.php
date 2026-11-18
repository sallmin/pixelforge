<?php

namespace App\Pixelforge;

final readonly class Submission
{
    /**
     * @param  array<string, string>|null  $rates
     */
    public function __construct(
        public bool $accepted,
        public bool $retry,
        public ?string $providerId,
        public ?string $status,
        public ?array $rates,
        public ?string $errorCode,
        public ?string $errorMessage,
        public int $retryAfter,
    ) {}
}
