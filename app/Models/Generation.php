<?php

namespace App\Models;

use App\Billing\Quality;
use App\Billing\Size;
use App\Generations\Status;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $provider_id
 * @property Status $status
 * @property string $prompt
 * @property Size $size
 * @property Quality $quality
 * @property array<int, string> $reference_images
 * @property int $estimate_minimum
 * @property int $estimate_maximum
 * @property array<string, string>|null $rates
 * @property int $provider_cost
 * @property int $charged
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'provider_id',
    'status',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'estimate_minimum',
    'estimate_maximum',
    'rates',
    'provider_cost',
    'charged',
    'error_code',
    'error_message',
    'image_url',
    'image_url_expires_at',
    'completed_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'size' => Size::class,
            'quality' => Quality::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'estimate_minimum' => 'integer',
            'estimate_maximum' => 'integer',
            'provider_cost' => 'integer',
            'charged' => 'integer',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
