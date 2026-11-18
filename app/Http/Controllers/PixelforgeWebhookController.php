<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyWebhook;
use App\Models\WebhookEvent;
use App\Pixelforge\Signature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    /**
     * Accept a Pixelforge webhook. Invalid signatures are rejected before any work is queued.
     */
    public function __invoke(Request $request, Signature $signature): Response
    {
        $payload = $request->getContent();
        $secret = config('services.pixelforge.webhook_secret');

        $valid = is_string($secret) && $signature->valid(
            $payload,
            (string) $request->headers->get('Pixelforge-Signature'),
            $secret,
            time(),
        );

        if (! $valid) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            abort(400, 'Invalid Pixelforge event.');
        }

        $providerGenerationId = $event['data']['object']['id'] ?? null;

        try {
            $record = WebhookEvent::query()->create([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'provider_generation_id' => is_string($providerGenerationId) ? $providerGenerationId : null,
                'payload' => $event,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->noContent();
        }

        if ($event['type'] === 'ping') {
            $record->update(['processed_at' => now()]);

            return response()->noContent();
        }

        ApplyWebhook::dispatch($record->id);

        return response()->noContent();
    }
}
