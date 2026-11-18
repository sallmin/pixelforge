<?php

namespace App\Pixelforge;

final class Signature
{
    private const int TOLERANCE_SECONDS = 300;

    public function valid(string $payload, string $header, string $secret, int $now): bool
    {
        if ($secret === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';

        if ($timestamp === '' || preg_match('/^\d+$/', $timestamp) !== 1) {
            return false;
        }

        $timestamp = (int) $timestamp;

        if (abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $given = $parts['v1'] ?? '';

        if (strlen($given) !== strlen($expected)) {
            return false;
        }

        return hash_equals($expected, $given);
    }
}
