<?php

namespace App\Actions\Generations;

use App\Actions\Billing\EstimateCost;
use App\Actions\Billing\Ledger;
use App\Billing\Quality;
use App\Billing\Size;
use App\Generations\Status;
use App\Jobs\SendGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Client;
use Illuminate\Support\Facades\DB;

final class CreateGeneration
{
    public function __construct(
        private Client $client,
        private EstimateCost $estimate,
        private Ledger $ledger,
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

        $generation = DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $quote): Generation {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $generation = Generation::query()->create([
                'user_id' => $locked->id,
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

            $this->ledger->hold($locked, $generation, $quote->maximumCharge->nanodollars());

            return $generation;
        });

        SendGeneration::dispatch($generation->id)->afterCommit();

        return $generation;
    }
}
