<?php

namespace App\Http\Controllers;

use App\Billing\Money;
use App\Models\CreditTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditPurchaseController extends Controller
{
    public const PACKS_IN_DOLLARS = [5, 10, 25];

    /** A pretend purchase: no payment is taken, the credits are simply added. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dollars' => ['required', 'integer', Rule::in(self::PACKS_IN_DOLLARS)],
        ]);

        $request->user()->creditTransactions()->create([
            'type' => CreditTransaction::PURCHASE,
            'amount_nanos' => $validated['dollars'] * Money::NANOS_PER_DOLLAR,
        ]);

        return back();
    }
}
