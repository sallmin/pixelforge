<?php

namespace App\Jobs;

use App\Actions\Billing\Ledger;
use App\Generations\Status;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 20;

    public function __construct(public string $generationId) {}

    /**
     * Send a pending generation. Rate limits and overloads are released back onto the queue.
     */
    public function handle(Client $client): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->status !== Status::Pending) {
            return;
        }

        try {
            $submission = $client->create($generation);
        } catch (ConnectionException $exception) {
            $this->retryOrFail($generation, 'unavailable', 'Pixelforge could not be reached. Nothing was charged.', 0);

            return;
        }

        if ($submission->accepted && $submission->providerId !== null) {
            $generation->update([
                'provider_id' => $submission->providerId,
                'status' => Status::tryFrom((string) $submission->status) ?? Status::Queued,
                'rates' => $submission->rates,
            ]);

            return;
        }

        if ($submission->retry) {
            $this->retryOrFail(
                $generation,
                $submission->errorCode ?? 'unavailable',
                $submission->errorMessage ?? 'Pixelforge is busy. Nothing was charged.',
                $submission->retryAfter,
            );

            return;
        }

        $this->markFailed(
            $generation,
            $submission->errorCode ?? 'rejected',
            $submission->errorMessage ?? 'Pixelforge rejected this generation. Nothing was charged.',
        );
    }

    public function failed(?Throwable $exception): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->status !== Status::Pending) {
            return;
        }

        $this->markFailed($generation, 'submission_failed', 'Pixelforge could not accept this generation. Nothing was charged.');
    }

    private function retryOrFail(Generation $generation, string $code, string $message, int $retryAfter): void
    {
        if ($this->attempts() >= $this->tries) {
            $this->markFailed($generation, $code, $message);

            return;
        }

        $this->release($this->delayFor($retryAfter));
    }

    private function delayFor(int $retryAfter): int
    {
        $backoff = [10, 30, 60, 120, 300];
        $index = min(max($this->attempts() - 1, 0), count($backoff) - 1);

        return max($retryAfter, $backoff[$index]);
    }

    private function markFailed(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message): void {
            $locked = Generation::query()->whereKey($generation->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status->isFinal()) {
                return;
            }

            User::query()->whereKey($locked->user_id)->lockForUpdate()->first();

            $locked->update([
                'status' => Status::Failed,
                'error_code' => $code,
                'error_message' => $message,
                'provider_cost' => 0,
                'charged' => 0,
            ]);

            app(Ledger::class)->release($locked);
        });
    }
}
