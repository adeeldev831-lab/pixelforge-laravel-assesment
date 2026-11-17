<?php

namespace App\Http\Controllers;

use App\Generations\GenerationUpdater;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    /**
     * Records the event and applies it in one transaction. Either both happen or neither
     * does: if applying fails we answer with an error, nothing is saved, and Pixelforge
     * delivers the event again.
     */
    public function __invoke(Request $request, GenerationUpdater $updater): Response
    {
        $event = $request->json()->all();

        DB::transaction(function () use ($event, $updater) {
            $isNew = DB::table('webhook_events')->insertOrIgnore([
                'id' => $event['id'],
                'type' => $event['type'],
                'payload' => json_encode($event),
                'received_at' => now(),
            ]);

            // Already recorded: Pixelforge delivered this event before.
            if (! $isNew) {
                return;
            }

            // Only generation.* events carry a generation ("ping" does not).
            if (str_starts_with($event['type'], 'generation.')) {
                $this->applyToGeneration($event['data']['object'], $updater);
            }
        });

        return response()->noContent();
    }

    /** @param  array<string, mixed>  $provider  the generation object in the event */
    private function applyToGeneration(array $provider, GenerationUpdater $updater): void
    {
        // Our own id comes back in metadata, so the generation is found even when the
        // webhook arrives before the create request has told us Pixelforge's id.
        $generation = Generation::find($provider['metadata']['generation_id'] ?? null)
            ?? Generation::firstWhere('provider_id', $provider['id']);

        if ($generation === null) {
            Log::warning('Pixelforge webhook for a generation we do not know.', ['provider_id' => $provider['id']]);

            return;
        }

        $updater->apply($generation, $provider);
    }
}
