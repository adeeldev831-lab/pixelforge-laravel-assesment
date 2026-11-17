<?php

namespace App\Billing;

use App\Pixelforge\PixelforgeClient;

/** What a user would pay for a generation, before it runs. */
class PriceEstimator
{
    public function __construct(
        private PixelforgeClient $pixelforge,
        private CostCalculator $costs,
    ) {}

    public function estimate(string $prompt, string $size, string $quality, int $referenceImages): CostRange
    {
        $providerCost = $this->costs->estimateProviderCost($this->pixelforge->pricing(), $prompt, $size, $quality, $referenceImages);

        return new CostRange(
            Money::withMarkup($providerCost->min),
            Money::withMarkup($providerCost->max),
        );
    }
}
