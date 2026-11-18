<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $generation_id
 * @property int $provider_cost
 * @property int $charged
 */
#[Fillable([
    'generation_id',
    'provider_cost',
    'charged',
])]
class GenerationCharge extends Model
{
    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_cost' => 'integer',
            'charged' => 'integer',
        ];
    }
}
