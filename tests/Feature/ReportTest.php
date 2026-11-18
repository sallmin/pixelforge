<?php

use App\Generations\Status;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('visitors who are not operators cannot open the report', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('reports'))->assertForbidden();
});

test('the report groups credits by utc completion day and user beside pixelforge usage', function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);

    Http::fake([
        'https://pixelforge.test/v1/usage' => Http::response([
            'object' => 'usage',
            'data' => [
                ['date' => '2026-11-18', 'cost_usd' => '0.000000100'],
                ['date' => '2026-11-19', 'cost_usd' => '0.000000001'],
            ],
        ]),
    ]);

    $admin = User::factory()->create(['name' => 'Ada']);
    $admin->forceFill(['is_admin' => true])->save();
    $other = User::factory()->create(['name' => 'Grace']);

    Generation::factory()->create([
        'user_id' => $admin->id,
        'status' => Status::Succeeded,
        'provider_cost' => 100,
        'charged' => 125,
        'completed_at' => '2026-11-18T23:30:00Z',
    ]);
    Generation::factory()->create([
        'user_id' => $other->id,
        'status' => Status::Succeeded,
        'provider_cost' => 40,
        'charged' => 50,
        'completed_at' => '2026-11-19T00:30:00Z',
    ]);
    Generation::factory()->create([
        'user_id' => $admin->id,
        'status' => Status::Failed,
        'provider_cost' => 0,
        'charged' => 0,
        'completed_at' => '2026-11-18T12:00:00Z',
    ]);

    $this->actingAs($admin)
        ->get(route('reports'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/Index')
            ->where('usage_available', true)
            ->has('days', 2)
            ->where('days.0.date', '2026-11-18')
            ->where('days.0.provider_cost.nanodollars', 100)
            ->where('days.0.credits.nanodollars', 125)
            ->where('days.0.profit.nanodollars', 25)
            ->where('days.0.matches', true)
            ->where('days.0.users.0.name', 'Ada')
            ->where('days.1.date', '2026-11-19')
            ->where('days.1.provider_cost.nanodollars', 40)
            ->where('days.1.matches', false)
            ->where('days.1.users.0.name', 'Grace')
        );
});
