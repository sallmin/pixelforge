<?php

use App\Actions\Billing\Ledger;
use App\Actions\Generations\ApplyEvent;
use App\Billing\Entry;
use App\Models\CreditEntry;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('buying credits adds them to the balance', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('credits.store'), ['amount' => '10.00'])
        ->assertRedirect(route('credits.index'));

    expect(app(Ledger::class)->available($user->id))->toBe(10_000_000_000);

    $this->actingAs($user)
        ->post(route('credits.store'), ['amount' => '0'])
        ->assertSessionHasErrors('amount');

    expect(app(Ledger::class)->available($user->id))->toBe(10_000_000_000);
});

test('a generation is refused when the balance cannot cover the maximum estimate', function () {
    prepareCreditPricing();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), [
            'prompt' => 'hi',
            'size' => '1024x1024',
            'quality' => 'low',
        ])
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors('balance');

    expect(Generation::query()->count())->toBe(0);
});

test('the maximum estimate is held and a second generation cannot spend it again', function () {
    prepareCreditPricing();

    $user = User::factory()->create();
    app(Ledger::class)->purchase($user, 21_818_750);

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'hi',
        'size' => '1024x1024',
        'quality' => 'low',
    ])->assertRedirect(route('generations.index'));

    expect(app(Ledger::class)->available($user->id))->toBe(0)
        ->and(CreditEntry::query()->where('type', Entry::Hold)->count())->toBe(1);

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), [
            'prompt' => 'hi',
            'size' => '1024x1024',
            'quality' => 'low',
        ])
        ->assertSessionHasErrors('balance');

    expect(Generation::query()->count())->toBe(1);
});

test('success replaces the hold with the actual charge', function () {
    prepareCreditPricing();

    $user = User::factory()->create();
    app(Ledger::class)->purchase($user, 1_000_000_000);

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'hi',
        'size' => '1024x1024',
        'quality' => 'low',
    ]);

    $generation = Generation::query()->sole();

    app(ApplyEvent::class)->applyObject($generation->id, [
        'id' => 'gen_test',
        'status' => 'succeeded',
        'usage' => [
            'input_text_tokens' => 1,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ],
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'completed_at' => '2026-11-18T12:00:00Z',
    ]);

    expect(app(Ledger::class)->available($user->id))->toBe(1_000_000_000 - 6_250)
        ->and(CreditEntry::query()->where('type', Entry::Charge)->sole()->amount)->toBe(-6_250)
        ->and(CreditEntry::query()->where('type', Entry::Release)->count())->toBe(1);
});

test('failure gives the hold back', function () {
    prepareCreditPricing();

    $user = User::factory()->create();
    app(Ledger::class)->purchase($user, 1_000_000_000);

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'hi',
        'size' => '1024x1024',
        'quality' => 'low',
    ]);

    $generation = Generation::query()->sole();

    app(ApplyEvent::class)->applyObject($generation->id, [
        'id' => 'gen_test',
        'status' => 'failed',
        'error' => ['code' => 'generation_failed', 'message' => 'You were not charged.'],
        'completed_at' => '2026-11-18T12:00:00Z',
    ]);

    expect(app(Ledger::class)->available($user->id))->toBe(1_000_000_000)
        ->and(CreditEntry::query()->where('type', Entry::Charge)->count())->toBe(0)
        ->and(CreditEntry::query()->where('type', Entry::Release)->count())->toBe(1);
});

function prepareCreditPricing(): void
{
    Cache::flush();

    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.api_key' => 'test-key',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(<<<'JSON'
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
JSON),
        'https://pixelforge.test/v1/generations' => Http::response([
            'id' => 'gen_test',
            'status' => 'queued',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ], 202),
    ]);
}
