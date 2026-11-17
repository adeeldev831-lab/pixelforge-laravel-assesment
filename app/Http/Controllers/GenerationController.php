<?php

namespace App\Http\Controllers;

use App\Billing\Money;
use App\Billing\PriceEstimator;
use App\Generations\GenerationStatus;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request, PixelforgeClient $pixelforge): Response
    {
        $outputTokens = $pixelforge->pricing()['tokens']['output'];

        return Inertia::render('Generations', [
            'qualities' => array_keys($outputTokens),
            'sizes' => array_keys(reset($outputTokens)),
            'generations' => GenerationResource::collection(
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
        ]);
    }

    public function store(GenerationRequest $request, PriceEstimator $estimator): RedirectResponse
    {
        $estimate = $request->estimate($estimator);

        $generation = $request->user()->generations()->create([
            'prompt' => $request->validated('prompt'),
            'size' => $request->validated('size'),
            'quality' => $request->validated('quality'),
            'reference_images' => $request->referenceImages(),
            'status' => GenerationStatus::Submitting,
            'estimated_min_nanos' => $estimate->min,
            'estimated_max_nanos' => $estimate->max,
        ]);

        // Pixelforge is called from the queue, so its limits and outages never block the page.
        SubmitGeneration::dispatch($generation);

        return to_route('generations.index');
    }
}
