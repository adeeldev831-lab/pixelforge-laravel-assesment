<?php

namespace App\Console\Commands;

use App\Generations\GenerationStatus;
use App\Generations\GenerationUpdater;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Webhook delivery isn't guaranteed. This asks Pixelforge directly about generations
 * that have been waiting for a while, and applies the answer exactly as a webhook would be.
 */
class SyncStaleGenerations extends Command
{
    protected $signature = 'generations:sync';

    protected $description = 'Fetch unfinished generations from Pixelforge in case their webhooks were lost';

    // Pixelforge allows 60 requests a minute for the whole account. This runs once a
    // minute, so it leaves most of that for submitting new generations.
    private const MAX_PER_RUN = 20;

    // A generation normally finishes within 90 seconds, and its webhook is retried for a while.
    private const STALE_AFTER_MINUTES = 2;

    // The submit job retries for a few minutes at most. Anything still unsent after this was lost.
    private const ABANDONED_AFTER_MINUTES = 15;

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): int
    {
        $this->failAbandonedSubmissions($updater);

        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('created_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->oldest('updated_at')
            ->limit(self::MAX_PER_RUN)
            ->get();

        foreach ($stale as $generation) {
            try {
                $updater->apply($generation, $pixelforge->generation($generation->provider_id));

                // Moves it to the back of the line for the next run, whether or not anything changed.
                $generation->touch();
            } catch (PixelforgeException|ConnectionException $e) {
                // Rate limited or unreachable: stop here and try again next minute.
                $this->warn("Stopped early: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->info("Checked {$stale->count()} generations.");

        return self::SUCCESS;
    }

    /**
     * A generation that never reached Pixelforge (its queued job was lost) would hold the
     * user's credits forever. It is failed at no cost; if Pixelforge did accept it after
     * all, its webhook revives it.
     */
    private function failAbandonedSubmissions(GenerationUpdater $updater): void
    {
        Generation::query()
            ->where('status', GenerationStatus::Submitting)
            ->where('created_at', '<', now()->subMinutes(self::ABANDONED_AFTER_MINUTES))
            ->get()
            ->each(fn (Generation $generation) => $updater->failSubmission(
                $generation,
                'submission_failed',
                'This image could not be sent to Pixelforge. You were not charged; please try again.',
            ));
    }
}
