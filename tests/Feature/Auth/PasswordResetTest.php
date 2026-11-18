<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    $notification = null;

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $sent) use (&$notification) {
        $notification = $sent;

        return true;
    });

    $this->get(route('password.reset', $notification->token))->assertOk();
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    $notification = null;

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $sent) use (&$notification) {
        $notification = $sent;

        return true;
    });

    $this->post(route('password.update'), [
        'token' => $notification->token,
        'email' => $user->email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});
