<?php

namespace App\Console\Commands;

use App\Actions\Generations\ApplyEvent;
use App\Generations\Status;
use App\Models\Generation;
use App\Pixelforge\Client;
use Illuminate\Console\Command;
use Throwable;

class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile';

    protected $description = 'Refresh generations whose webhook has not arrived';

    public function handle(Client $client, ApplyEvent $apply): int
    {
        $stuck = Generation::query()
            ->whereIn('status', [Status::Queued, Status::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<=', now()->subMinutes(3))
            ->orderBy('updated_at')
            ->limit(20)
            ->get();

        foreach ($stuck as $generation) {
            try {
                $object = $client->generation((string) $generation->provider_id);
            } catch (Throwable) {
                continue;
            }

            if (! is_array($object)) {
                continue;
            }

            $apply->applyObject($generation->id, $object);
        }

        return self::SUCCESS;
    }
}
