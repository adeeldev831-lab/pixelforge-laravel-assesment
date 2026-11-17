<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * Every amount in the app is a whole number of nanodollars (1 USD = 1,000,000,000),
 * so no calculation ever touches a float. Pixelforge reports costs to nine decimal
 * places, which is exactly one nanodollar.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    private const TOKENS_PER_RATE_UNIT = 1_000_000;

    /** "0.046125" => 46125000 */
    public static function fromDecimal(string $dollars): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', $dollars, $parts)) {
            throw new InvalidArgumentException("Not a dollar amount with at most nine decimals: {$dollars}");
        }

        return (int) $parts[1] * self::NANOS_PER_DOLLAR + (int) str_pad($parts[2] ?? '', 9, '0');
    }

    /** 46125000 => "0.046125000" */
    public static function toDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $nanos = abs($nanos);

        return sprintf('%s%d.%09d', $sign, intdiv($nanos, self::NANOS_PER_DOLLAR), $nanos % self::NANOS_PER_DOLLAR);
    }

    /**
     * Turns a rate in dollars per 1M tokens into nanodollars per token: "12.50" => 12500.
     * A rate that is not a whole number of nanodollars per token can't be billed exactly,
     * so it is refused rather than rounded.
     */
    public static function perToken(string $dollarsPerMillionTokens): int
    {
        $nanosPerMillion = self::fromDecimal($dollarsPerMillionTokens);

        if ($nanosPerMillion % self::TOKENS_PER_RATE_UNIT !== 0) {
            throw new InvalidArgumentException("Rate is finer than one nanodollar per token: {$dollarsPerMillionTokens}");
        }

        return intdiv($nanosPerMillion, self::TOKENS_PER_RATE_UNIT);
    }

    /**
     * What the user pays for something that cost us $providerCost. A fraction of a
     * nanodollar is rounded up, so the markup is never less than promised.
     */
    public static function withMarkup(int $providerCost): int
    {
        $percent = 100 + config('pixelforge.markup_percent');

        return intdiv($providerCost * $percent + 99, 100);
    }
}
