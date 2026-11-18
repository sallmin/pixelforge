<?php

namespace App\Actions\Billing;

use App\Billing\Money;
use App\Generations\Status;
use App\Models\Generation;
use App\Pixelforge\Client;
use Illuminate\Support\Collection;
use RuntimeException;

final class Summarize
{
    public function __construct(private Client $client) {}

    /**
     * Credits, provider cost, and profit by the UTC completion day, beside Pixelforge usage.
     *
     * @return array{days: list<array<string, mixed>>, usage_available: bool}
     */
    public function __invoke(): array
    {
        $recorded = $this->recorded();
        $reported = $this->reported();
        $usageAvailable = $reported !== null;
        $reported ??= collect();

        $dates = $recorded->keys()
            ->merge($reported->keys())
            ->unique()
            ->sort()
            ->values();

        $days = [];

        foreach ($dates as $date) {
            /** @var Collection<int, Generation> $generations */
            $generations = $recorded->get($date, collect());
            $provider = (int) $generations->sum('provider_cost');
            $credits = (int) $generations->sum('charged');
            $usage = $reported->get($date);

            if ($provider === 0 && $credits === 0 && $usage === null) {
                continue;
            }

            $days[] = [
                'date' => $date,
                'credits' => $this->money($credits),
                'provider_cost' => $this->money($provider),
                'profit' => $this->money($credits - $provider),
                'usage' => is_int($usage) ? $this->money($usage) : null,
                'matches' => is_int($usage) && $usage === $provider,
                'users' => $generations
                    ->groupBy('user_id')
                    ->map(function (Collection $rows): array {
                        $credits = (int) $rows->sum('charged');
                        $provider = (int) $rows->sum('provider_cost');
                        $user = $rows->first()?->user;

                        return [
                            'name' => $user?->name ?? 'Unknown',
                            'credits' => $this->money($credits),
                            'provider_cost' => $this->money($provider),
                            'profit' => $this->money($credits - $provider),
                        ];
                    })
                    ->sortBy('name')
                    ->values()
                    ->all(),
            ];
        }

        return [
            'days' => $days,
            'usage_available' => $usageAvailable,
        ];
    }

    /**
     * @return Collection<string, Collection<int, Generation>>
     */
    private function recorded(): Collection
    {
        return Generation::query()
            ->where('status', Status::Succeeded)
            ->whereNotNull('completed_at')
            ->with('user:id,name')
            ->get()
            ->groupBy(fn (Generation $generation): string => $generation->completed_at->utc()->toDateString());
    }

    /**
     * @return Collection<string, int>|null
     */
    private function reported(): ?Collection
    {
        try {
            $days = $this->client->usage();
        } catch (RuntimeException) {
            return null;
        }

        $reported = collect();

        foreach ($days as $day) {
            $date = $day['date'] ?? null;
            $cost = $day['cost_usd'] ?? null;

            if (! is_string($date) || ! is_string($cost)) {
                return null;
            }

            $nanodollars = Money::fromUsd($cost)->nanodollars();

            if ($nanodollars !== 0) {
                $reported->put($date, $nanodollars);
            }
        }

        return $reported;
    }

    /**
     * @return array{label: string, exact: string, nanodollars: int}
     */
    private function money(int $nanodollars): array
    {
        $money = Money::fromNanodollars($nanodollars);

        return [
            'label' => $money->label(),
            'exact' => $money->exact(),
            'nanodollars' => $nanodollars,
        ];
    }
}
