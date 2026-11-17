<?php

namespace App\Jobs;

use App\Generations\GenerationUpdater;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Sends a generation to Pixelforge. Rate limits, the concurrency limit, overloads and
 * network errors are retried with a growing delay, a few times at most. Retrying is safe
 * because the generation's id is the idempotency key.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    private const MAX_DELAY_SECONDS = 120;

    public function __construct(public Generation $generation) {}

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): void
    {
        try {
            $provider = $pixelforge->createGeneration($this->generation);
        } catch (PixelforgeException $e) {
            if ($e->isRetryable() && $this->canRetry()) {
                $this->release($this->delayBeforeRetry($e->retryAfter));
            } else {
                $updater->failSubmission($this->generation, $e->errorCode, $e->getMessage());
            }

            return;
        } catch (ConnectionException) {
            if ($this->canRetry()) {
                $this->release($this->delayBeforeRetry(null));
            } else {
                $updater->failSubmission($this->generation, 'connection_error', 'Pixelforge could not be reached. You were not charged; please try again.');
            }

            return;
        }

        $updater->apply($this->generation, $provider);
    }

    /** Called by the queue when the job dies for a reason handle() didn't expect. */
    public function failed(?Throwable $exception): void
    {
        app(GenerationUpdater::class)->failSubmission(
            $this->generation,
            'submission_failed',
            'Something went wrong while sending this to Pixelforge. You were not charged; please try again.',
        );
    }

    private function canRetry(): bool
    {
        return $this->attempts() < $this->tries;
    }

    /** Pixelforge's Retry-After (or 2 seconds), doubled on every attempt. */
    private function delayBeforeRetry(?int $retryAfter): int
    {
        $delay = ($retryAfter ?? 2) * 2 ** ($this->attempts() - 1);

        return min($delay, self::MAX_DELAY_SECONDS);
    }
}
