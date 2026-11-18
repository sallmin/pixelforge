<?php

namespace App\Billing;

use InvalidArgumentException;

final readonly class Pricing
{
    private int $varianceNumerator;

    private int $varianceScale;

    /**
     * @param  array<string, array<string, int>>  $outputTokens
     * @param  array<string, array<string, int>>  $reasoningTokens
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        string $textTokenVariance,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
    ) {
        if ($this->charactersPerTextToken < 1) {
            throw new InvalidArgumentException('Characters per text token must be at least 1.');
        }

        if ($this->tokensPerReferenceImage < 0) {
            throw new InvalidArgumentException('Tokens per reference image cannot be negative.');
        }

        $variance = Decimal::parse($textTokenVariance);

        if ($variance->numerator() >= $variance->scale()) {
            throw new InvalidArgumentException('Text token variance must be less than 1.');
        }

        $this->varianceNumerator = $variance->numerator();
        $this->varianceScale = $variance->scale();
    }

    public static function fromApi(string $json): self
    {
        $payload = json_decode($json, true);

        if (! is_array($payload) || ! is_array($payload['rates'] ?? null) || ! is_array($payload['tokens'] ?? null)) {
            throw new InvalidArgumentException('Pixelforge pricing is incomplete.');
        }

        if (preg_match('/"text_token_variance"\s*:\s*(\d+(?:\.\d+)?)/', $json, $matches) !== 1) {
            throw new InvalidArgumentException('Pixelforge pricing is missing the text token variance.');
        }

        /** @var array<string, mixed> $rates */
        $rates = $payload['rates'];
        /** @var array<string, mixed> $tokens */
        $tokens = $payload['tokens'];

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            if (! is_string($rates[$key] ?? null)) {
                throw new InvalidArgumentException('Pixelforge pricing rates must be decimal strings.');
            }
        }

        if (! is_int($tokens['characters_per_text_token'] ?? null) || ! is_int($tokens['per_reference_image'] ?? null)) {
            throw new InvalidArgumentException('Pixelforge pricing token counts are incomplete.');
        }

        if (! is_array($tokens['output'] ?? null) || ! is_array($tokens['reasoning'] ?? null)) {
            throw new InvalidArgumentException('Pixelforge pricing token tables are incomplete.');
        }

        return new self(
            rates: Rates::perMillion(
                $rates['input_text_tokens'],
                $rates['input_image_tokens'],
                $rates['output_tokens'],
                $rates['reasoning_tokens'],
            ),
            charactersPerTextToken: $tokens['characters_per_text_token'],
            textTokenVariance: $matches[1],
            tokensPerReferenceImage: $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    public function outputTokens(Quality $quality, Size $size): int
    {
        $tokens = $this->outputTokens[$quality->value][$size->value] ?? null;

        if (! is_int($tokens) || $tokens < 0) {
            throw new InvalidArgumentException("No output token count for {$quality->value} {$size->value}.");
        }

        return $tokens;
    }

    public function minimumReasoningTokens(Quality $quality): int
    {
        return $this->reasoning($quality)['min'];
    }

    public function maximumReasoningTokens(Quality $quality): int
    {
        return $this->reasoning($quality)['max'];
    }

    /**
     * Smallest integer token count inside the published variance band.
     */
    public function minimumTextTokens(int $estimated): int
    {
        if ($estimated < 0) {
            throw new InvalidArgumentException('Estimated text tokens cannot be negative.');
        }

        return $this->ceilDiv(
            $estimated * ($this->varianceScale - $this->varianceNumerator),
            $this->varianceScale,
        );
    }

    /**
     * Largest integer token count inside the published variance band.
     */
    public function maximumTextTokens(int $estimated): int
    {
        if ($estimated < 0) {
            throw new InvalidArgumentException('Estimated text tokens cannot be negative.');
        }

        return intdiv(
            $estimated * ($this->varianceScale + $this->varianceNumerator),
            $this->varianceScale,
        );
    }

    /**
     * @return array{min: int, max: int}
     */
    private function reasoning(Quality $quality): array
    {
        $range = $this->reasoningTokens[$quality->value] ?? null;
        $minimum = is_array($range) ? ($range['min'] ?? null) : null;
        $maximum = is_array($range) ? ($range['max'] ?? null) : null;

        if (! is_int($minimum) || ! is_int($maximum) || $minimum < 0 || $maximum < $minimum) {
            throw new InvalidArgumentException("No reasoning range for {$quality->value}.");
        }

        return ['min' => $minimum, 'max' => $maximum];
    }

    private function ceilDiv(int $numerator, int $denominator): int
    {
        if ($numerator <= 0) {
            return 0;
        }

        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
