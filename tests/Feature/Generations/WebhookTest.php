<?php

use App\Generations\Status;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
});

test('rejects a bad signature', function () {
    $generation = generationForWebhook();

    postWebhook(succeededBody($generation, 'evt_bad'), 't=1700000000,v1=not-a-signature')
        ->assertBadRequest();

    expect($generation->refresh()->status)->toBe(Status::Pending)
        ->and($generation->charged)->toBe(0)
        ->and(WebhookEvent::query()->count())->toBe(0);
});

test('rejects an expired timestamp', function () {
    $generation = generationForWebhook();
    $body = succeededBody($generation, 'evt_old');

    postWebhook($body, timestamp: time() - 301)->assertBadRequest();

    expect($generation->refresh()->charged)->toBe(0)
        ->and(WebhookEvent::query()->count())->toBe(0);
});

test('charges a succeeded generation once when the same event arrives twice', function () {
    $generation = generationForWebhook();
    $body = succeededBody($generation, 'evt_once');

    postWebhook($body)->assertNoContent();
    postWebhook($body)->assertNoContent();

    $generation->refresh();

    expect($generation->status)->toBe(Status::Succeeded)
        ->and($generation->provider_cost)->toBe(305_510_000)
        ->and($generation->charged)->toBe(381_887_500)
        ->and($generation->usage)->toBe([
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
        ])
        ->and(Generation::query()->count())->toBe(1)
        ->and(GenerationCharge::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->count())->toBe(1);
});

test('does not move a succeeded generation back to processing', function () {
    $generation = generationForWebhook();

    postWebhook(succeededBody($generation, 'evt_done'))->assertNoContent();
    postWebhook(processingBody($generation, 'evt_late'))->assertNoContent();

    $generation->refresh();

    expect($generation->status)->toBe(Status::Succeeded)
        ->and($generation->charged)->toBe(381_887_500)
        ->and(GenerationCharge::query()->count())->toBe(1);
});

test('applies a webhook that arrives before the provider id is stored', function () {
    $generation = generationForWebhook();

    expect($generation->provider_id)->toBeNull();

    postWebhook(succeededBody($generation, 'evt_early'))->assertNoContent();

    $generation->refresh();

    expect($generation->provider_id)->toBe('gen_provider')
        ->and($generation->status)->toBe(Status::Succeeded)
        ->and($generation->charged)->toBe(381_887_500);
});

test('a failed generation stays free', function () {
    $generation = generationForWebhook();

    postWebhook(failedBody($generation, 'evt_fail'))->assertNoContent();

    $generation->refresh();

    expect($generation->status)->toBe(Status::Failed)
        ->and($generation->provider_cost)->toBe(0)
        ->and($generation->charged)->toBe(0)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->error_message)->toBe('You were not charged.')
        ->and(GenerationCharge::query()->count())->toBe(0);
});

test('answers a ping without charging', function () {
    $generation = generationForWebhook();

    postWebhook(json_encode([
        'id' => 'evt_ping',
        'object' => 'event',
        'type' => 'ping',
        'data' => [],
    ], JSON_THROW_ON_ERROR))->assertNoContent();

    expect($generation->refresh()->status)->toBe(Status::Pending)
        ->and($generation->charged)->toBe(0)
        ->and(WebhookEvent::query()->where('type', 'ping')->whereNotNull('processed_at')->count())->toBe(1);
});

function generationForWebhook(): Generation
{
    return Generation::factory()->create([
        'user_id' => User::factory(),
        'status' => Status::Pending,
        'provider_id' => null,
        'provider_cost' => 0,
        'charged' => 0,
    ]);
}

function succeededBody(Generation $generation, string $eventId): string
{
    return eventBody($generation, $eventId, 'generation.succeeded', 'succeeded', [
        'usage' => [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ],
        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_provider.png',
        'image_url_expires_at' => '2026-11-05T14:03:29Z',
        'completed_at' => '2026-11-04T14:03:29Z',
    ]);
}

function processingBody(Generation $generation, string $eventId): string
{
    return eventBody($generation, $eventId, 'generation.processing', 'processing');
}

function failedBody(Generation $generation, string $eventId): string
{
    return eventBody($generation, $eventId, 'generation.failed', 'failed', [
        'error' => [
            'code' => 'generation_failed',
            'message' => 'You were not charged.',
        ],
        'completed_at' => '2026-11-04T14:03:29Z',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function eventBody(Generation $generation, string $eventId, string $type, string $status, array $overrides = []): string
{
    $encoded = json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => [
            'object' => [
                'id' => 'gen_provider',
                'object' => 'generation',
                'status' => $status,
                'metadata' => ['generation_id' => $generation->id],
                'usage' => null,
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'error' => null,
                'image_url' => null,
                'image_url_expires_at' => null,
                'completed_at' => null,
                ...$overrides,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    if ($encoded === false) {
        throw new RuntimeException('Could not encode the webhook.');
    }

    return $encoded;
}

function postWebhook(string $body, ?string $header = null, ?int $timestamp = null): TestResponse
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) config('services.pixelforge.webhook_secret'));

    return test()->call(
        method: 'POST',
        uri: '/webhooks/pixelforge',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $header ?? "t={$timestamp},v1={$signature}",
        ],
        content: $body,
    );
}
