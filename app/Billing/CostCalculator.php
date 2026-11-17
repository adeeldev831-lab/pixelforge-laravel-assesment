<?php

namespace App\Billing;

final class CostCalculator
{
    private const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * What Pixelforge bills for the given token counts, in nanodollars.
     *
     * @param  array<string, int>  $usage  token counts by type
     * @param  array<string, string>  $rates  dollars per 1M tokens by type
     */
    public function providerCost(array $usage, array $rates): int
    {
        $cost = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $cost += $usage[$type] * Money::perToken($rates[$type]);
        }

        return $cost;
    }

    /**
     * What Pixelforge could bill for a request that hasn't run yet. Text and reasoning
     * tokens are only known afterwards, so the result is a range.
     *
     * @param  array<string, mixed>  $pricing  the body of GET /v1/pricing
     */
    public function estimateProviderCost(array $pricing, string $prompt, string $size, string $quality, int $referenceImages): CostRange
    {
        $tokens = $pricing['tokens'];

        $textTokens = (int) ceil(mb_strlen($prompt) / $tokens['characters_per_text_token']);
        $variancePercent = (int) round($tokens['text_token_variance'] * 100);

        $fixed = [
            'input_image_tokens' => $referenceImages * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$quality][$size],
        ];

        $min = $fixed + [
            'input_text_tokens' => intdiv($textTokens * (100 - $variancePercent), 100),
            'reasoning_tokens' => $tokens['reasoning'][$quality]['min'],
        ];

        $max = $fixed + [
            'input_text_tokens' => intdiv($textTokens * (100 + $variancePercent) + 99, 100),
            'reasoning_tokens' => $tokens['reasoning'][$quality]['max'],
        ];

        return new CostRange(
            $this->providerCost($min, $pricing['rates']),
            $this->providerCost($max, $pricing['rates']),
        );
    }
}
