<?php

use App\Generations\Status;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('a stuck generation is refreshed through the same charge path', function () {
    prepareReconcile();

    $user = User::factory()->create();
    $stuck = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => Status::Queued,
        'provider_id' => 'gen_provider',
        'provider_cost' => 0,
        'charged' => 0,
    ]);

    $this->travel(5)->minutes();

    $fresh = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => Status::Queued,
        'provider_id' => 'gen_fresh',
    ]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    $stuck->refresh();

    expect($stuck->status)->toBe(Status::Succeeded)
        ->and($stuck->provider_cost)->toBe(305_510_000)
        ->and($stuck->charged)->toBe(381_887_500)
        ->and(GenerationCharge::query()->count())->toBe(1)
        ->and($fresh->refresh()->status)->toBe(Status::Queued);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'gen_fresh'));

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect(GenerationCharge::query()->count())->toBe(1)
        ->and($stuck->refresh()->charged)->toBe(381_887_500);
});

test('reconciliation runs every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'generations:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

function prepareReconcile(): void
{
    Cache::flush();

    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.api_key' => 'test-key',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://pixelforge.test/v1/generations/gen_provider' => Http::response([
            'id' => 'gen_provider',
            'status' => 'succeeded',
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
            ],
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_provider.png',
            'image_url_expires_at' => now()->addDay()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
            'error' => null,
        ]),
    ]);
}
