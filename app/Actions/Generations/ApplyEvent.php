<?php

namespace App\Actions\Generations;

use App\Actions\Billing\CalculateCost;
use App\Actions\Billing\Ledger;
use App\Billing\Rates;
use App\Billing\TokenUsage;
use App\Generations\Status;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ApplyEvent
{
    public function __construct(
        private CalculateCost $calculateCost,
        private Ledger $ledger,
    ) {}

    /**
     * Move a generation forward from one webhook, charging it at most once.
     */
    public function __invoke(WebhookEvent $event): void
    {
        if ($event->processed_at !== null || $event->type === 'ping') {
            return;
        }

        DB::transaction(function () use ($event): void {
            $locked = WebhookEvent::query()->whereKey($event->id)->lockForUpdate()->first();

            if ($locked === null || $locked->processed_at !== null) {
                return;
            }

            $generation = $this->generation($locked);

            if ($generation === null) {
                $locked->update(['processed_at' => now()]);

                return;
            }

            $object = $this->object($locked->payload);
            $this->applyObject($generation->id, $object);

            $locked->update(['processed_at' => now()]);
        });
    }

    /**
     * Move one generation forward from a provider generation object.
     * A row is charged only when it first reaches succeeded.
     *
     * @param  array<string, mixed>  $object
     */
    public function applyObject(string $generationId, array $object): void
    {
        DB::transaction(function () use ($generationId, $object): void {
            $generation = Generation::query()->whereKey($generationId)->lockForUpdate()->first();

            if ($generation === null) {
                return;
            }

            $incoming = Status::tryFrom(is_string($object['status'] ?? null) ? $object['status'] : '');

            if ($incoming === null || ! $generation->status->canAdvanceTo($incoming)) {
                return;
            }

            if ($incoming === Status::Succeeded) {
                $this->succeed($generation, $object);
            } elseif ($incoming === Status::Failed) {
                $this->fail($generation, $object);
            } else {
                $generation->update([
                    'status' => $incoming,
                    'provider_id' => $this->providerId($object) ?? $generation->provider_id,
                ]);
            }
        });
    }

    private function generation(WebhookEvent $event): ?Generation
    {
        $object = $this->object($event->payload);
        $metadata = $object['metadata'] ?? null;
        $localId = is_array($metadata) ? ($metadata['generation_id'] ?? null) : null;

        if (is_string($localId) && $localId !== '') {
            $generation = Generation::query()->find($localId);

            if ($generation !== null) {
                return $generation;
            }
        }

        $providerId = $this->providerId($object);

        if ($providerId === null) {
            return null;
        }

        return Generation::query()->where('provider_id', $providerId)->first();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function succeed(Generation $generation, array $object): void
    {
        $usage = $this->usage($object);
        $rates = $this->rates($object);
        $cost = ($this->calculateCost)($usage, $rates);

        $generation->update([
            'status' => Status::Succeeded,
            'provider_id' => $this->providerId($object) ?? $generation->provider_id,
            'rates' => [
                'input_text_tokens' => $object['rates']['input_text_tokens'],
                'input_image_tokens' => $object['rates']['input_image_tokens'],
                'output_tokens' => $object['rates']['output_tokens'],
                'reasoning_tokens' => $object['rates']['reasoning_tokens'],
            ],
            'usage' => [
                'input_text_tokens' => $usage->inputText,
                'input_image_tokens' => $usage->inputImage,
                'output_tokens' => $usage->output,
                'reasoning_tokens' => $usage->reasoning,
            ],
            'provider_cost' => $cost->provider->nanodollars(),
            'charged' => $cost->charge->nanodollars(),
            'error_code' => null,
            'error_message' => null,
            'image_url' => is_string($object['image_url'] ?? null) ? $object['image_url'] : null,
            'image_url_expires_at' => is_string($object['image_url_expires_at'] ?? null) ? $object['image_url_expires_at'] : null,
            'completed_at' => is_string($object['completed_at'] ?? null) ? $object['completed_at'] : now(),
        ]);

        GenerationCharge::query()->create([
            'generation_id' => $generation->id,
            'provider_cost' => $cost->provider->nanodollars(),
            'charged' => $cost->charge->nanodollars(),
        ]);

        $this->lockOwner($generation);
        $this->ledger->charge($generation, $cost->charge->nanodollars());
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function fail(Generation $generation, array $object): void
    {
        $error = is_array($object['error'] ?? null) ? $object['error'] : [];

        $generation->update([
            'status' => Status::Failed,
            'provider_id' => $this->providerId($object) ?? $generation->provider_id,
            'provider_cost' => 0,
            'charged' => 0,
            'usage' => null,
            'error_code' => is_string($error['code'] ?? null) ? $error['code'] : 'generation_failed',
            'error_message' => is_string($error['message'] ?? null) ? $error['message'] : 'The generation failed. You were not charged.',
            'image_url' => null,
            'completed_at' => is_string($object['completed_at'] ?? null) ? $object['completed_at'] : now(),
        ]);

        $this->lockOwner($generation);
        $this->ledger->release($generation);
    }

    private function lockOwner(Generation $generation): void
    {
        User::query()->whereKey($generation->user_id)->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function object(array $payload): array
    {
        $data = $payload['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        return is_array($object) ? $object : [];
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function providerId(array $object): ?string
    {
        $id = $object['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function usage(array $object): TokenUsage
    {
        $usage = $object['usage'] ?? null;

        if (! is_array($usage)) {
            throw new InvalidArgumentException('Succeeded generation is missing usage.');
        }

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            if (! is_int($usage[$key] ?? null)) {
                throw new InvalidArgumentException('Succeeded generation usage is incomplete.');
            }
        }

        return new TokenUsage(
            $usage['input_text_tokens'],
            $usage['input_image_tokens'],
            $usage['output_tokens'],
            $usage['reasoning_tokens'],
        );
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function rates(array $object): Rates
    {
        $rates = $object['rates'] ?? null;

        if (! is_array($rates)) {
            throw new InvalidArgumentException('Succeeded generation is missing rates.');
        }

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            if (! is_string($rates[$key] ?? null)) {
                throw new InvalidArgumentException('Succeeded generation rates are incomplete.');
            }
        }

        return Rates::perMillion(
            $rates['input_text_tokens'],
            $rates['input_image_tokens'],
            $rates['output_tokens'],
            $rates['reasoning_tokens'],
        );
    }
}
