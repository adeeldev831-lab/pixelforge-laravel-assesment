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

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): int
    {
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
}
