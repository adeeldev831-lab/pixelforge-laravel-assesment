<?php

namespace Tests\Unit\Billing;

use App\Billing\CostCalculator;
use App\Billing\Money;
use PHPUnit\Framework\TestCase;

class CostCalculatorTest extends TestCase
{
    public function test_it_prices_the_succeeded_generation_from_the_api_docs(): void
    {
        $usage = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184];

        $cost = (new CostCalculator)->providerCost($usage, $this->pricing()['rates']);

        // 12 x 5000 + 765 x 10000 + 6450 x 40000 + 3184 x 12500
        $this->assertSame('0.305510000', Money::toDecimal($cost));
    }

    public function test_it_matches_the_usage_example_from_the_api_docs(): void
    {
        $usage = ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950];

        $cost = (new CostCalculator)->providerCost($usage, $this->pricing()['rates']);

        $this->assertSame('0.169080000', Money::toDecimal($cost));
    }

    public function test_it_bills_at_the_rates_given_not_at_fixed_ones(): void
    {
        $usage = ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 100, 'reasoning_tokens' => 0];
        $rates = ['input_text_tokens' => '6.00', 'input_image_tokens' => '10.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '12.50'];

        $this->assertSame(10 * 6_000 + 100 * 80_000, (new CostCalculator)->providerCost($usage, $rates));
    }

    public function test_the_estimate_matches_the_worked_example_from_the_api_docs(): void
    {
        $range = (new CostCalculator)->estimateProviderCost($this->pricing(), str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame('0.373875000', Money::toDecimal($range->max));

        // 85 text + 1530 image + 6450 output + 1000 reasoning tokens
        $this->assertSame(85 * 5_000 + 1530 * 10_000 + 6450 * 40_000 + 1000 * 12_500, $range->min);
    }

    public function test_the_estimate_counts_characters_not_bytes_and_rounds_the_bounds_outwards(): void
    {
        // 9 characters (18 bytes) => ceil(9 / 4) = 3 tokens => between floor(2.55) = 2 and ceil(3.45) = 4.
        $range = (new CostCalculator)->estimateProviderCost($this->pricing(), str_repeat('é', 9), '1024x1024', 'low', 0);

        $this->assertSame(2 * 5_000 + 280 * 40_000, $range->min);
        $this->assertSame(4 * 5_000 + 280 * 40_000 + 500 * 12_500, $range->max);
    }

    private function pricing(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../Fixtures/pricing.json'), true);
    }
}
