<?php

namespace App\Actions\Billing;

use App\Billing\Entry;
use App\Billing\InsufficientCredits;
use App\Models\CreditEntry;
use App\Models\Generation;
use App\Models\User;

final class Ledger
{
    public function available(int $userId): int
    {
        return (int) CreditEntry::query()->where('user_id', $userId)->sum('amount');
    }

    public function purchase(User $user, int $nanodollars): void
    {
        CreditEntry::query()->create([
            'user_id' => $user->id,
            'type' => Entry::Purchase,
            'amount' => $nanodollars,
        ]);
    }

    /**
     * Reserve the maximum estimate. The user row must already be locked.
     */
    public function hold(User $user, Generation $generation, int $nanodollars): void
    {
        if ($this->available($user->id) < $nanodollars) {
            throw new InsufficientCredits('You do not have enough credits for this estimate.');
        }

        CreditEntry::query()->create([
            'user_id' => $user->id,
            'generation_id' => $generation->id,
            'type' => Entry::Hold,
            'amount' => -$nanodollars,
        ]);
    }

    /**
     * Give a hold back. A generation that never reserved credits is left unchanged.
     */
    public function release(Generation $generation): void
    {
        $hold = CreditEntry::query()
            ->where('generation_id', $generation->id)
            ->where('type', Entry::Hold)
            ->first();

        if ($hold === null) {
            return;
        }

        CreditEntry::query()->firstOrCreate(
            [
                'generation_id' => $generation->id,
                'type' => Entry::Release,
            ],
            [
                'user_id' => $generation->user_id,
                'amount' => -$hold->amount,
            ],
        );
    }

    /**
     * Replace the hold with the actual user charge.
     */
    public function charge(Generation $generation, int $nanodollars): void
    {
        $this->release($generation);

        CreditEntry::query()->firstOrCreate(
            [
                'generation_id' => $generation->id,
                'type' => Entry::Charge,
            ],
            [
                'user_id' => $generation->user_id,
                'amount' => -$nanodollars,
            ],
        );
    }
}
