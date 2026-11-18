<?php

namespace App\Actions\Generations;

use App\Actions\Billing\EstimateCost;
use App\Billing\Quality;
use App\Billing\Size;
use App\Generations\Status;
use App\Jobs\SendGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Client;

final class CreateGeneration
{
    public function __construct(
        private Client $client,
        private EstimateCost $estimate,
    ) {}

    /**
     * @param  array<int|string, string>  $referenceImages
     */
    public function __invoke(
        User $user,
        string $prompt,
        Size $size,
        Quality $quality,
        array $referenceImages,
    ): Generation {
        $quote = ($this->estimate)(
            $prompt,
            $quality,
            $size,
            count($referenceImages),
            $this->client->pricing(),
        );

        $generation = Generation::query()->create([
            'user_id' => $user->id,
            'status' => Status::Pending,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => array_values($referenceImages),
            'estimate_minimum' => $quote->minimumCharge->nanodollars(),
            'estimate_maximum' => $quote->maximumCharge->nanodollars(),
            'provider_cost' => 0,
            'charged' => 0,
        ]);

        SendGeneration::dispatch($generation->id);

        return $generation;
    }
}
