<?php

namespace App\Billing;

/** The least and the most a generation can cost, in nanodollars. */
final readonly class CostRange
{
    public function __construct(
        public int $min,
        public int $max,
    ) {}
}
