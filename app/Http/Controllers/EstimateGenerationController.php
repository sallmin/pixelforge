<?php

namespace App\Http\Controllers;

use App\Actions\Billing\EstimateCost;
use App\Billing\Quality;
use App\Billing\Size;
use App\Pixelforge\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class EstimateGenerationController extends Controller
{
    public function __invoke(Request $request, Client $client, EstimateCost $estimate): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(Size::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['nullable', 'string'],
        ]);

        $images = array_values(array_filter(
            $validated['reference_images'] ?? [],
            fn (mixed $url): bool => is_string($url) && trim($url) !== '',
        ));

        try {
            $quote = $estimate(
                $validated['prompt'] ?? '',
                Quality::from($validated['quality']),
                Size::from($validated['size']),
                count($images),
                $client->pricing(),
            );
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'Pricing is temporarily unavailable.',
            ], 503);
        }

        return response()->json([
            'minimum' => $quote->minimumCharge->label(),
            'maximum' => $quote->maximumCharge->label(),
            'minimum_exact' => $quote->minimumCharge->exact(),
            'maximum_exact' => $quote->maximumCharge->exact(),
            'reason' => 'Reasoning tokens vary, and the text token count can land within 15% of the character estimate.',
        ]);
    }
}
