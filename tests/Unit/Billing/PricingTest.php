<?php

use App\Billing\Pricing;
use App\Billing\Quality;
use App\Billing\Size;

test('reads published rates and the text token band from the pricing payload', function () {
    $pricing = Pricing::fromApi(<<<'JSON'
{
  "object": "pricing",
  "currency": "usd",
  "unit": "per_1m_tokens",
  "rates": {
    "input_text_tokens": "5.00",
    "input_image_tokens": "10.00",
    "output_tokens": "40.00",
    "reasoning_tokens": "12.50"
  },
  "tokens": {
    "characters_per_text_token": 4,
    "text_token_variance": 0.15,
    "per_reference_image": 765,
    "output": {
      "low": {"1024x1024": 280, "1536x1024": 420, "1024x1536": 420},
      "medium": {"1024x1024": 1100, "1536x1024": 1650, "1024x1536": 1650},
      "high": {"1024x1024": 4300, "1536x1024": 6450, "1024x1536": 6450}
    },
    "reasoning": {
      "low": {"min": 0, "max": 500},
      "medium": {"min": 200, "max": 2000},
      "high": {"min": 1000, "max": 8000}
    }
  }
}
JSON);

    expect($pricing->maximumTextTokens(100))->toBe(115)
        ->and($pricing->minimumTextTokens(100))->toBe(85)
        ->and($pricing->outputTokens(Quality::High, Size::Landscape))->toBe(6450)
        ->and($pricing->rates->inputText->cost(1)->nanodollars())->toBe(5_000);
});
