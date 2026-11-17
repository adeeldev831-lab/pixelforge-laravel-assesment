<?php

namespace App\Http\Controllers;

use App\Billing\Money;
use App\Billing\PriceEstimator;
use App\Generations\GenerationStatus;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request, PixelforgeClient $pixelforge): Response
    {
        // Closures, so the page's status polling (which asks only for "generations") skips the rest.
        return Inertia::render('Generations', [
            'qualities' => fn () => array_keys($pixelforge->pricing()['tokens']['output']),
            'sizes' => fn () => array_keys(reset($pixelforge->pricing()['tokens']['output'])),
            'credits' => fn () => [
                'balance' => Money::toDecimal($request->user()->balanceNanos()),
                'reserved' => Money::toDecimal($request->user()->reservedNanos()),
                'available' => Money::toDecimal($request->user()->availableNanos()),
            ],
            'creditPacks' => CreditPurchaseController::PACKS_IN_DOLLARS,
            'generations' => fn () => GenerationResource::collection(
                $request->user()->generations()->latest('id')->limit(50)->get()
            )->resolve(),
        ]);
    }

    /** The price shown before submitting. The same calculation is stored by store(). */
    public function estimate(GenerationRequest $request, PriceEstimator $estimator): JsonResponse
    {
        $estimate = $request->estimate($estimator);

        return response()->json([
            'min' => Money::toDecimal($estimate->min),
            'max' => Money::toDecimal($estimate->max),
            'affordable' => $request->user()->availableNanos() >= $estimate->max,
        ]);
    }

    public function store(GenerationRequest $request, PriceEstimator $estimator): RedirectResponse
    {
        $estimate = $request->estimate($estimator);

        $generation = DB::transaction(function () use ($request, $estimate) {
            // Locking the user makes their submissions take turns, so two at the same
            // moment can't both spend the same credits.
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            if ($user->availableNanos() < $estimate->max) {
                throw ValidationException::withMessages([
                    'balance' => 'You don\'t have enough credits for this image. Buy credits or wait for running generations to finish.',
                ]);
            }

            // From here on the maximum price is held until the generation finishes.
            return $user->generations()->create([
                'prompt' => $request->validated('prompt'),
                'size' => $request->validated('size'),
                'quality' => $request->validated('quality'),
                'reference_images' => $request->referenceImages(),
                'status' => GenerationStatus::Submitting,
                'estimated_min_nanos' => $estimate->min,
                'estimated_max_nanos' => $estimate->max,
            ]);
        });

        // Sent once this response is on its way, so the page never waits for Pixelforge
        // and the first attempt doesn't depend on a queue worker being up.
        SubmitGeneration::dispatchAfterResponse($generation);

        return to_route('generations.index');
    }
}
