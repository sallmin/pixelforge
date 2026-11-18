<?php

namespace App\Actions\Billing;

use App\Billing\Pricing;
use App\Billing\Quality;
use App\Billing\Quote;
use App\Billing\Size;
use App\Billing\TokenCount;
use App\Billing\TokenUsage;
use InvalidArgumentException;

final class EstimateCost
{
    /**
     * Estimate a generation before Pixelforge reports usage.
     *
     * Text tokens start at ceil(characters / characters_per_text_token), counted with mb_strlen.
     * The returned band is every integer inside that estimate plus or minus the pricing variance.
     * Image tokens and output tokens are exact. Reasoning tokens use the quality range from pricing.
     * Each end of the range includes the 25% markup, rounded up to the next nanodollar.
     */
    public function __invoke(
        string $prompt,
        Quality $quality,
        Size $size,
        int $referenceImages,
        Pricing $pricing,
    ): Quote {
        if ($referenceImages < 0) {
            throw new InvalidArgumentException('Reference image count cannot be negative.');
        }

        $estimatedTextTokens = $this->ceilDiv(mb_strlen($prompt), $pricing->charactersPerTextToken);
        $text = new TokenCount(
            $pricing->minimumTextTokens($estimatedTextTokens),
            $pricing->maximumTextTokens($estimatedTextTokens),
        );
        $imageTokens = $referenceImages * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokens($quality, $size);
        $minimumReasoning = $pricing->minimumReasoningTokens($quality);
        $maximumReasoning = $pricing->maximumReasoningTokens($quality);
        $minimumProviderCost = $pricing->rates->cost(new TokenUsage(
            $text->minimum,
            $imageTokens,
            $outputTokens,
            $minimumReasoning,
        ));
        $maximumProviderCost = $pricing->rates->cost(new TokenUsage(
            $text->maximum,
            $imageTokens,
            $outputTokens,
            $maximumReasoning,
        ));

        return new Quote(
            minimumProviderCost: $minimumProviderCost,
            maximumProviderCost: $maximumProviderCost,
            minimumCharge: $minimumProviderCost->withMarkup(),
            maximumCharge: $maximumProviderCost->withMarkup(),
            text: $text,
            image: new TokenCount($imageTokens, $imageTokens),
            output: new TokenCount($outputTokens, $outputTokens),
            reasoning: new TokenCount($minimumReasoning, $maximumReasoning),
        );
    }

    private function ceilDiv(int $numerator, int $denominator): int
    {
        if ($numerator <= 0) {
            return 0;
        }

        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
