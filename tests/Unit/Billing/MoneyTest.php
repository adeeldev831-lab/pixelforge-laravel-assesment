<?php

namespace Tests\Unit\Billing;

use App\Billing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_decimal_dollars_convert_to_nanodollars_and_back_without_loss(string $dollars, int $nanos, string $formatted): void
    {
        $this->assertSame($nanos, Money::fromDecimal($dollars));
        $this->assertSame($formatted, Money::toDecimal($nanos));
    }

    public static function amounts(): array
    {
        return [
            ['0.046125000', 46_125_000, '0.046125000'],
            ['0.373875', 373_875_000, '0.373875000'],
            ['12', 12_000_000_000, '12.000000000'],
            ['0.000000001', 1, '0.000000001'],
        ];
    }

    #[DataProvider('unrepresentableAmounts')]
    public function test_amounts_that_cannot_be_represented_exactly_are_refused(string $dollars): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal($dollars);
    }

    public static function unrepresentableAmounts(): array
    {
        return [['0.0000000001'], ['1e3'], ['-1'], [''], ['1.']];
    }

    public function test_a_rate_per_million_tokens_becomes_nanodollars_per_token(): void
    {
        $this->assertSame(5_000, Money::perToken('5.00'));
        $this->assertSame(12_500, Money::perToken('12.50'));
        $this->assertSame(1_000, Money::perToken('1.00'));
        $this->assertSame(1, Money::perToken('0.001'));
    }

    public function test_a_rate_finer_than_one_nanodollar_per_token_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::perToken('0.0005');
    }

    public function test_the_markup_is_25_percent_and_rounds_a_fraction_of_a_nanodollar_up(): void
    {
        $this->assertSame(467_343_750, Money::withMarkup(373_875_000));
        $this->assertSame(5, Money::withMarkup(4));
        $this->assertSame(7, Money::withMarkup(5)); // 6.25
        $this->assertSame(2, Money::withMarkup(1)); // 1.25
        $this->assertSame(0, Money::withMarkup(0));
    }
}
