<?php

namespace App\Generations;

use App\Billing\CostCalculator;
use App\Billing\Money;
use App\Models\CreditTransaction;
use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place a generation changes status or gets a cost. Webhooks, the response to
 * our own create request and the polling fallback all come through here, in any order
 * and any number of times, and the result is the same.
 */
class GenerationUpdater
{
    public function __construct(private CostCalculator $costs) {}

    /**
     * Brings our record up to date with a generation object from Pixelforge.
     *
     * @param  array<string, mixed>  $provider  the generation object
     */
    public function apply(Generation $generation, array $provider): Generation
    {
        return $this->locked($generation, function (Generation $generation) use ($provider) {
            if ($generation->provider_id === null) {
                $generation->provider_id = $provider['id'];

                // We gave up on submitting it (say, every attempt timed out), yet Pixelforge
                // did accept it and will bill it. Start over from what Pixelforge says.
                if ($generation->status === GenerationStatus::Failed) {
                    $generation->fill([
                        'status' => GenerationStatus::Submitting,
                        'error_code' => null,
                        'error_message' => null,
                        'provider_cost_nanos' => null,
                        'charged_nanos' => null,
                        'completed_at' => null,
                    ]);
                }
            }

            $status = GenerationStatus::from($provider['status']);

            if ($generation->status->canMoveTo($status)) {
                $generation->status = $status;

                match ($status) {
                    GenerationStatus::Succeeded => $this->recordSuccess($generation, $provider),
                    GenerationStatus::Failed => $this->recordFailure($generation, $provider['error']['code'], $provider['error']['message'], $provider['completed_at']),
                    default => null,
                };
            }
        });
    }

    /** Marks a generation that Pixelforge never accepted as failed. It costs nothing. */
    public function failSubmission(Generation $generation, string $code, string $message): Generation
    {
        return $this->locked($generation, function (Generation $generation) use ($code, $message) {
            if ($generation->status->canMoveTo(GenerationStatus::Failed)) {
                $generation->status = GenerationStatus::Failed;
                $this->recordFailure($generation, $code, $message, now());
            }
        });
    }

    /** @param  array<string, mixed>  $provider */
    private function recordSuccess(Generation $generation, array $provider): void
    {
        // Billed at the rates on the generation itself, which were fixed when it was created.
        $providerCost = $this->costs->providerCost($provider['usage'], $provider['rates']);

        $generation->usage = $provider['usage'];
        $generation->rates = $provider['rates'];
        $generation->provider_cost_nanos = $providerCost;
        $generation->charged_nanos = Money::withMarkup($providerCost);

        // Takes the charge from the user's balance, in the same transaction as the status change.
        CreditTransaction::create([
            'user_id' => $generation->user_id,
            'type' => CreditTransaction::CHARGE,
            'amount_nanos' => -$generation->charged_nanos,
            'generation_id' => $generation->id,
        ]);
        $generation->image_url = $provider['image_url'];
        $generation->image_url_expires_at = Carbon::parse($provider['image_url_expires_at']);
        $generation->completed_at = Carbon::parse($provider['completed_at']);
    }

    private function recordFailure(Generation $generation, string $code, string $message, mixed $completedAt): void
    {
        $generation->error_code = $code;
        $generation->error_message = $message;
        $generation->provider_cost_nanos = 0;
        $generation->charged_nanos = 0;
        $generation->completed_at = Carbon::parse($completedAt);
    }

    /**
     * Runs $change on a freshly loaded, row-locked copy of the generation, so two updates
     * arriving at the same moment are applied one after the other.
     *
     * @param  callable(Generation): void  $change
     */
    private function locked(Generation $generation, callable $change): Generation
    {
        return DB::transaction(function () use ($generation, $change) {
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            $change($generation);
            $generation->save();

            return $generation;
        });
    }
}
