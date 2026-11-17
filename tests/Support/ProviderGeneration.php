<?php

namespace Tests\Support;

use App\Models\Generation;

/** Builds generation objects the way Pixelforge's API returns them. */
class ProviderGeneration
{
    public const USAGE = [
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
        'total_tokens' => 10411,
    ];

    // The cost of USAGE at the rates below: 12 x 5000 + 765 x 10000 + 6450 x 40000 + 3184 x 12500.
    public const PROVIDER_COST_NANOS = 305_510_000;

    // PROVIDER_COST_NANOS plus 25%.
    public const CHARGED_NANOS = 381_887_500;

    public static function make(Generation $generation, string $status, array $overrides = []): array
    {
        return array_merge([
            'id' => $generation->provider_id ?? 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_id' => $generation->id],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => $status === 'queued' ? null : '2026-11-04T14:03:13Z',
            'completed_at' => null,
        ], $overrides);
    }

    public static function succeeded(Generation $generation, array $overrides = []): array
    {
        return self::make($generation, 'succeeded', array_merge([
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_example.png?expires=1762351409&signature=abc',
            'image_url_expires_at' => '2026-11-05T14:03:29Z',
            'usage' => self::USAGE,
            'completed_at' => '2026-11-04T14:03:29Z',
        ], $overrides));
    }

    public static function failed(Generation $generation, array $overrides = []): array
    {
        return self::make($generation, 'failed', array_merge([
            'error' => [
                'code' => 'internal_error',
                'message' => 'Something went wrong on our side while generating. You were not charged; please retry.',
            ],
            'completed_at' => '2026-11-04T14:03:29Z',
        ], $overrides));
    }
}
