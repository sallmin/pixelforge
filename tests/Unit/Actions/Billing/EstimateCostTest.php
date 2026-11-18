<?php

use App\Actions\Billing\EstimateCost;
use App\Billing\Pricing;
use App\Billing\Quality;
use App\Billing\Quote;
use App\Billing\Rates;
use App\Billing\Size;

test('prices the documented maximum for a high landscape generation', function () {
    $quote = estimateGeneration(str_repeat('a', 400), Quality::High, Size::Landscape, 2);

    expect($quote->text->maximum)->toBe(115)
        ->and($quote->text->minimum)->toBe(85)
        ->and($quote->image->maximum)->toBe(1530)
        ->and($quote->output->maximum)->toBe(6450)
        ->and($quote->reasoning->maximum)->toBe(8000)
        ->and($quote->reasoning->minimum)->toBe(1000)
        ->and($quote->maximumProviderCost->nanodollars())->toBe(373_875_000)
        ->and($quote->maximumProviderCost->usd())->toBe('0.373875000')
        ->and($quote->maximumCharge->nanodollars())->toBe(467_343_750)
        ->and($quote->minimumProviderCost->nanodollars())->toBe(286_225_000)
        ->and($quote->minimumCharge->nanodollars())->toBe(357_781_250);
});

test('counts unicode characters rather than bytes', function () {
    $emoji = estimateGeneration(str_repeat('🎨', 400), Quality::High, Size::Landscape, 2);
    $letters = estimateGeneration(str_repeat('a', 400), Quality::High, Size::Landscape, 2);

    expect($emoji->maximumProviderCost->nanodollars())->toBe($letters->maximumProviderCost->nanodollars());

    $twoEmoji = estimateGeneration('🎨🎨', Quality::High, Size::Landscape, 0);

    expect($twoEmoji->text->maximum)->toBe(1)
        ->and($twoEmoji->maximumProviderCost->nanodollars())->toBe(358_005_000);
});

test('keeps text tokens inside the fifteen percent band', function () {
    $quote = estimateGeneration('abcdefg', Quality::Low, Size::Square, 0, charactersPerTextToken: 1);

    expect($quote->text->minimum)->toBe(6)
        ->and($quote->text->maximum)->toBe(8)
        ->and($quote->minimumProviderCost->nanodollars())->toBe(11_230_000)
        ->and($quote->maximumProviderCost->nanodollars())->toBe(17_490_000);
});

test('reads the text divisor and image tokens from pricing', function () {
    $shorterTokens = estimateGeneration(
        str_repeat('a', 400),
        Quality::High,
        Size::Landscape,
        2,
        charactersPerTextToken: 2,
    );
    $cheaperImages = estimateGeneration(
        str_repeat('a', 400),
        Quality::High,
        Size::Landscape,
        2,
        tokensPerReferenceImage: 10,
    );

    expect($shorterTokens->text->maximum)->toBe(230)
        ->and($shorterTokens->maximumProviderCost->nanodollars())->toBe(374_450_000)
        ->and($cheaperImages->image->maximum)->toBe(20)
        ->and($cheaperImages->maximumProviderCost->nanodollars())->toBe(358_775_000);
});

test('prices an empty low quality image with no reasoning floor', function () {
    $quote = estimateGeneration('', Quality::Low, Size::Square, 0);

    expect($quote->text->maximum)->toBe(0)
        ->and($quote->reasoning->minimum)->toBe(0)
        ->and($quote->minimumProviderCost->nanodollars())->toBe(11_200_000)
        ->and($quote->minimumCharge->nanodollars())->toBe(14_000_000);
});

test('maximum provider cost covers every quality and size', function (Quality $quality, Size $size, int $outputTokens, int $maximumProviderCost) {
    $quote = estimateGeneration('', $quality, $size, 0);

    expect($quote->output->maximum)->toBe($outputTokens)
        ->and($quote->output->minimum)->toBe($outputTokens)
        ->and($quote->maximumProviderCost->nanodollars())->toBe($maximumProviderCost);
})->with([
    'low square' => [Quality::Low, Size::Square, 280, 17_450_000],
    'low landscape' => [Quality::Low, Size::Landscape, 420, 23_050_000],
    'low portrait' => [Quality::Low, Size::Portrait, 420, 23_050_000],
    'medium square' => [Quality::Medium, Size::Square, 1100, 69_000_000],
    'medium landscape' => [Quality::Medium, Size::Landscape, 1650, 91_000_000],
    'medium portrait' => [Quality::Medium, Size::Portrait, 1650, 91_000_000],
    'high square' => [Quality::High, Size::Square, 4300, 272_000_000],
    'high landscape' => [Quality::High, Size::Landscape, 6450, 358_000_000],
    'high portrait' => [Quality::High, Size::Portrait, 6450, 358_000_000],
]);

test('rejects a negative reference image count', function () {
    expect(fn () => estimateGeneration('a lighthouse', Quality::High, Size::Square, -1))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects a quality and size missing from pricing', function () {
    expect(fn () => estimateGeneration('a lighthouse', Quality::High, Size::Portrait, 0, outputTokens: [
        'low' => ['1024x1024' => 280],
    ]))->toThrow(InvalidArgumentException::class);
});

test('rejects a variance of one or more', function () {
    expect(fn () => generationPricing(textTokenVariance: '1'))
        ->toThrow(InvalidArgumentException::class);
});

function estimateGeneration(
    string $prompt,
    Quality $quality,
    Size $size,
    int $referenceImages,
    int $charactersPerTextToken = 4,
    int $tokensPerReferenceImage = 765,
    ?array $outputTokens = null,
): Quote {
    return (new EstimateCost)(
        $prompt,
        $quality,
        $size,
        $referenceImages,
        generationPricing($charactersPerTextToken, $tokensPerReferenceImage, $outputTokens),
    );
}

/**
 * @param  array<string, array<string, int>>|null  $outputTokens
 */
function generationPricing(
    int $charactersPerTextToken = 4,
    int $tokensPerReferenceImage = 765,
    ?array $outputTokens = null,
    string $textTokenVariance = '0.15',
): Pricing {
    return new Pricing(
        rates: Rates::perMillion('5.00', '10.00', '40.00', '12.50'),
        charactersPerTextToken: $charactersPerTextToken,
        textTokenVariance: $textTokenVariance,
        tokensPerReferenceImage: $tokensPerReferenceImage,
        outputTokens: $outputTokens ?? [
            'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
            'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
            'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
        ],
        reasoningTokens: [
            'low' => ['min' => 0, 'max' => 500],
            'medium' => ['min' => 200, 'max' => 2000],
            'high' => ['min' => 1000, 'max' => 8000],
        ],
    );
}
