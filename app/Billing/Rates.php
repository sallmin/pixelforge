<?php

namespace App\Billing;

final readonly class Rates
{
    public function __construct(
        public Rate $inputText,
        public Rate $inputImage,
        public Rate $output,
        public Rate $reasoning,
    ) {}

    public static function perMillion(
        string $inputText,
        string $inputImage,
        string $output,
        string $reasoning,
    ): self {
        return new self(
            Rate::perMillion($inputText),
            Rate::perMillion($inputImage),
            Rate::perMillion($output),
            Rate::perMillion($reasoning),
        );
    }

    public function cost(TokenUsage $usage): Money
    {
        return $this->inputText->cost($usage->inputText)
            ->plus($this->inputImage->cost($usage->inputImage))
            ->plus($this->output->cost($usage->output))
            ->plus($this->reasoning->cost($usage->reasoning));
    }
}
