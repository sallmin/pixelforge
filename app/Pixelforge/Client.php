<?php

namespace App\Pixelforge;

use App\Billing\Pricing;
use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class Client
{
    private const array RETRYABLE = [
        'overloaded',
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
    ];

    public function pricing(): Pricing
    {
        return Cache::remember('pixelforge.pricing', now()->addMinutes(5), function (): Pricing {
            $response = Http::pixelforge()->get('pricing');

            if (! $response->successful()) {
                throw new RuntimeException('Pixelforge pricing is unavailable.');
            }

            return Pricing::fromApi($response->body());
        });
    }

    /**
     * @throws ConnectionException
     */
    public function create(Generation $generation): Submission
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'metadata' => [
                'generation_id' => $generation->id,
            ],
        ];

        if ($generation->reference_images !== []) {
            $payload['reference_images'] = array_values($generation->reference_images);
        }

        $response = Http::pixelforge()
            ->withHeader('Idempotency-Key', $generation->id)
            ->post('generations', $payload);

        return $this->submission($response);
    }

    private function submission(Response $response): Submission
    {
        if ($response->status() === 202) {
            $providerId = $response->json('id');

            return new Submission(
                accepted: is_string($providerId) && $providerId !== '',
                retry: false,
                providerId: is_string($providerId) ? $providerId : null,
                status: $response->json('status'),
                rates: $this->rates($response->json('rates')),
                errorCode: null,
                errorMessage: null,
                retryAfter: 0,
            );
        }

        $code = $response->json('error.code');
        $message = $response->json('error.message');
        $errorCode = is_string($code) ? $code : null;
        $errorMessage = is_string($message) ? $message : 'Pixelforge rejected this generation.';
        $retryAfter = max((int) $response->header('Retry-After'), 0);

        if ($this->shouldRetry($response->status(), $errorCode)) {
            return new Submission(
                accepted: false,
                retry: true,
                providerId: null,
                status: null,
                rates: null,
                errorCode: $errorCode,
                errorMessage: $errorMessage,
                retryAfter: $retryAfter,
            );
        }

        return new Submission(
            accepted: false,
            retry: false,
            providerId: null,
            status: null,
            rates: null,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            retryAfter: 0,
        );
    }

    private function shouldRetry(int $status, ?string $code): bool
    {
        if ($code === 'daily_limit_exceeded') {
            return false;
        }

        if ($code !== null && in_array($code, self::RETRYABLE, true)) {
            return true;
        }

        return $status === 429 || $status === 503;
    }

    /**
     * @return array<string, string>|null
     */
    private function rates(mixed $rates): ?array
    {
        if (! is_array($rates)) {
            return null;
        }

        $snapshot = [];

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            if (! isset($rates[$key]) || ! is_string($rates[$key])) {
                return null;
            }

            $snapshot[$key] = $rates[$key];
        }

        return $snapshot;
    }
}
