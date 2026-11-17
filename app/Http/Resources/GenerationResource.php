<?php

namespace App\Http\Resources;

use App\Billing\Money;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    /**
     * Money is sent as exact decimal dollar strings, never as numbers.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'status' => $this->status->value,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'estimated_min' => Money::toDecimal($this->estimated_min_nanos),
            'estimated_max' => Money::toDecimal($this->estimated_max_nanos),
            'charged' => $this->charged_nanos === null ? null : Money::toDecimal($this->charged_nanos),
            'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
