<?php

namespace App\Models;

use App\Generations\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'estimated_min_nanos' => 'integer',
            'estimated_max_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charged_nanos' => 'integer',
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
