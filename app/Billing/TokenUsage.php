<?php

namespace App\Billing;

use InvalidArgumentException;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {
        foreach ([$this->inputText, $this->inputImage, $this->output, $this->reasoning] as $tokens) {
            if ($tokens < 0) {
                throw new InvalidArgumentException('Token counts cannot be negative.');
            }
        }
    }
}
