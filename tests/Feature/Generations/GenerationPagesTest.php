<?php

use App\Generations\Status;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the history page shows only the signed in user generations', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Generation::factory()->create([
        'user_id' => $user->id,
        'prompt' => 'My lighthouse',
        'status' => Status::Failed,
        'error_message' => 'The model could not draw this.',
        'provider_cost' => 0,
        'charged' => 0,
    ]);
    Generation::factory()->create([
        'user_id' => $other->id,
        'prompt' => 'Someone else',
    ]);

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations', 1)
            ->where('generations.0.prompt', 'My lighthouse')
            ->where('generations.0.status_label', 'Failed')
            ->where('generations.0.error_message', 'The model could not draw this.')
        );
});

test('the generate page is available to a verified user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('generations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('generations/Create'));
});

test('an estimate uses the shared pricing math', function () {
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
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('generations.estimate', [
            'prompt' => 'hi',
            'size' => '1024x1024',
            'quality' => 'low',
        ]))
        ->assertOk()
        ->assertJson([
            'minimum' => '$0.014006',
            'maximum' => '$0.021819',
            'minimum_exact' => '$0.014006250',
            'maximum_exact' => '$0.021818750',
        ]);

    $this->actingAs($user)
        ->getJson(route('generations.estimate', [
            'prompt' => 'hi',
            'size' => '1024x1024',
            'quality' => 'low',
        ]))
        ->assertOk();
});
