<?php

use App\Pixelforge\Signature;

test('accepts a signature for the raw body', function () {
    $body = '{"id":"evt_1"}';
    $timestamp = 1_700_000_000;
    $secret = 'whsec_test';
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    expect((new Signature)->valid($body, "t={$timestamp},v1={$signature}", $secret, $timestamp))->toBeTrue();
});

test('rejects a signature for a different body', function () {
    $timestamp = 1_700_000_000;
    $secret = 'whsec_test';
    $signature = hash_hmac('sha256', $timestamp.'.{"id":"evt_1"}', $secret);

    expect((new Signature)->valid('{"id":"evt_2"}', "t={$timestamp},v1={$signature}", $secret, $timestamp))->toBeFalse();
});

test('rejects a signature older than five minutes', function () {
    $body = '{"id":"evt_1"}';
    $timestamp = 1_700_000_000;
    $secret = 'whsec_test';
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    expect((new Signature)->valid($body, "t={$timestamp},v1={$signature}", $secret, $timestamp + 301))->toBeFalse();
});

test('rejects a missing signature', function () {
    expect((new Signature)->valid('{}', 't=1700000000', 'whsec_test', 1_700_000_000))->toBeFalse();
});
