<?php

use App\Generations\Status;
use App\Jobs\SendGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('guests are redirected to login', function () {
    $this->post(route('generations.store'))->assertRedirect(route('login'));
});

test('unverified users cannot submit a generation', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), generationPayload())
        ->assertRedirect(route('verification.notice'));

    expect(Generation::query()->count())->toBe(0);
});

test('invalid generations are rejected before pixelforge is called', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('generations.store'), generationPayload([
            'prompt' => str_repeat('a', 4001),
            'size' => 'huge',
            'quality' => 'ultra',
            'reference_images' => [
                'https://example.com/1.png',
                'https://example.com/2.png',
                'https://example.com/3.png',
                'https://example.com/4.png',
                'ftp://example.com/5.png',
            ],
        ]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images', 'reference_images.4']);

    expect(Generation::query()->count())->toBe(0);
});

test('a generation is stored once and sent with its id as the idempotency key', function () {
    preparePixelforge(Http::response(acceptedGeneration(), 202));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), generationPayload())
        ->assertRedirect(route('dashboard'));

    $generation = Generation::query()->sole();

    expect($generation->status)->toBe(Status::Queued)
        ->and($generation->provider_id)->toBe('gen_test')
        ->and($generation->charged)->toBe(0)
        ->and($generation->provider_cost)->toBe(0)
        ->and($generation->estimate_minimum)->toBe(14_006_250)
        ->and($generation->estimate_maximum)->toBe(21_818_750)
        ->and($generation->rates)->toBe([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);

    Http::assertSent(function ($request) use ($generation) {
        if (! str_ends_with($request->url(), '/generations')) {
            return false;
        }

        $body = $request->data();

        return $request->hasHeader('Idempotency-Key', $generation->id)
            && $body['metadata'] === ['generation_id' => $generation->id]
            && $body['prompt'] === 'hi'
            && $body['size'] === '1024x1024'
            && $body['quality'] === 'low'
            && ! array_key_exists('reference_images', $body);
    });

    expect(generationRequests())->toHaveCount(1);
});

test('reference image urls are sent with the generation', function () {
    preparePixelforge(Http::response(acceptedGeneration(), 202));

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('generations.store'), generationPayload([
        'reference_images' => ['https://example.com/style.png'],
    ]))->assertRedirect(route('dashboard'));

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/generations')
            && $request->data()['reference_images'] === ['https://example.com/style.png'];
    });

    expect(Generation::query()->count())->toBe(1);
});

test('an overloaded response is retried and still records one generation', function () {
    preparePixelforge(Http::sequence()
        ->push(['error' => ['code' => 'overloaded', 'message' => 'Pixelforge is overloaded.']], 503, ['Retry-After' => '2'])
        ->push(acceptedGeneration(), 202));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), generationPayload())
        ->assertRedirect(route('dashboard'));

    $generation = Generation::query()->sole();

    expect($generation->status)->toBe(Status::Pending)
        ->and($generation->provider_id)->toBeNull()
        ->and($generation->charged)->toBe(0);

    app()->call([new SendGeneration($generation->id), 'handle']);

    $generation->refresh();

    expect($generation->status)->toBe(Status::Queued)
        ->and($generation->provider_id)->toBe('gen_test')
        ->and(Generation::query()->count())->toBe(1);

    $keys = generationRequests()
        ->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0])
        ->all();

    expect($keys)->toBe([$generation->id, $generation->id]);
});

test('a rate limit releases the job for the retry after delay', function () {
    Queue::fake();
    preparePixelforge(Http::response([
        'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down.'],
    ], 429, ['Retry-After' => '30']));

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('generations.store'), generationPayload());

    Queue::assertPushed(SendGeneration::class, 1);

    $generation = Generation::query()->sole();
    $job = new SendGeneration($generation->id);
    $job->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertReleased(30);
    expect($generation->refresh()->status)->toBe(Status::Pending)
        ->and($generation->charged)->toBe(0)
        ->and(Generation::query()->count())->toBe(1)
        ->and(generationRequests())->toHaveCount(1);
});

test('a short retry after still waits for the backoff', function () {
    Queue::fake();
    preparePixelforge(Http::response([
        'error' => ['code' => 'concurrency_limit_exceeded', 'message' => 'Too many in progress.'],
    ], 429, ['Retry-After' => '2']));

    $user = User::factory()->create();
    $this->actingAs($user)->post(route('generations.store'), generationPayload());

    $generation = Generation::query()->sole();
    $job = new SendGeneration($generation->id);
    $job->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertReleased(10);
    expect($generation->refresh()->status)->toBe(Status::Pending)
        ->and(Generation::query()->count())->toBe(1);
});

test('retries stop after the cap and the generation stays free', function () {
    Queue::fake();
    preparePixelforge(Http::response([
        'error' => ['code' => 'overloaded', 'message' => 'Pixelforge is overloaded.'],
    ], 503, ['Retry-After' => '2']));

    $user = User::factory()->create();
    $this->actingAs($user)->post(route('generations.store'), generationPayload());

    $generation = Generation::query()->sole();
    $job = new SendGeneration($generation->id);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 5;

    app()->call([$job, 'handle']);

    expect($job->job->isReleased())->toBeFalse()
        ->and($generation->refresh()->status)->toBe(Status::Failed)
        ->and($generation->error_code)->toBe('overloaded')
        ->and($generation->charged)->toBe(0)
        ->and(Generation::query()->count())->toBe(1);
});

test('a blocked prompt fails immediately and is free', function () {
    preparePixelforge(Http::response([
        'error' => [
            'code' => 'content_policy_violation',
            'message' => 'This prompt is not allowed.',
        ],
    ], 400));

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('generations.store'), generationPayload([
        'prompt' => 'a lighthouse [blocked]',
    ]));

    $generation = Generation::query()->sole();

    expect($generation->status)->toBe(Status::Failed)
        ->and($generation->error_code)->toBe('content_policy_violation')
        ->and($generation->error_message)->toBe('This prompt is not allowed.')
        ->and($generation->charged)->toBe(0)
        ->and($generation->provider_cost)->toBe(0)
        ->and(generationRequests())->toHaveCount(1);
});

test('a daily limit fails immediately', function () {
    preparePixelforge(Http::response([
        'error' => [
            'code' => 'daily_limit_exceeded',
            'message' => 'The daily limit has been reached.',
        ],
    ], 429));

    $user = User::factory()->create();
    $this->actingAs($user)->post(route('generations.store'), generationPayload());

    $generation = Generation::query()->sole();

    expect($generation->status)->toBe(Status::Failed)
        ->and($generation->error_code)->toBe('daily_limit_exceeded')
        ->and($generation->charged)->toBe(0)
        ->and(generationRequests())->toHaveCount(1);
});

test('a replayed acceptance updates the same generation', function () {
    preparePixelforge(Http::response(acceptedGeneration(), 202, ['Idempotent-Replayed' => 'true']));

    $user = User::factory()->create();
    $this->actingAs($user)->post(route('generations.store'), generationPayload());

    $generation = Generation::query()->sole();

    expect($generation->status)->toBe(Status::Queued)
        ->and($generation->provider_id)->toBe('gen_test')
        ->and(Generation::query()->count())->toBe(1)
        ->and(generationRequests())->toHaveCount(1);
});

function preparePixelforge(mixed $generations): void
{
    Cache::flush();

    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.api_key' => 'test-key',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(pixelforgePricingBody()),
        'https://pixelforge.test/v1/generations' => $generations,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generationPayload(array $overrides = []): array
{
    return [
        'prompt' => 'hi',
        'size' => '1024x1024',
        'quality' => 'low',
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function acceptedGeneration(): array
{
    return [
        'id' => 'gen_test',
        'status' => 'queued',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
    ];
}

function pixelforgePricingBody(): string
{
    return <<<'JSON'
{
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
JSON;
}

function generationRequests(): Collection
{
    return Http::recorded()->filter(
        fn (array $pair) => str_ends_with($pair[0]->url(), '/generations'),
    )->values();
}
