<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

test('the seeder creates an operator who can open the report', function () {
    $this->seed();
    $this->seed();

    $admin = User::query()->where('email', 'admin@example.com')->sole();

    expect($admin)->not->toBeNull()
        ->and($admin->is_admin)->toBeTrue()
        ->and(User::query()->where('email', 'test@example.com')->first()?->is_admin)->toBeFalse();

    $this->assertTrue(auth()->attempt([
        'email' => 'admin@example.com',
        'password' => 'password',
    ]));

    Http::fake([
        '*' => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($admin)->get(route('reports'))->assertOk();
});
