<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyEvent;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ApplyWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(public int $webhookEventId) {}

    /**
     * Apply one stored Pixelforge event.
     */
    public function handle(ApplyEvent $apply): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null) {
            return;
        }

        $apply($event);
    }
}
