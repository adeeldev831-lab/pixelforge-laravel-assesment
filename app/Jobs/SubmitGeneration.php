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
 * Sends a generation to Pixelforge. The first attempt runs right after the user's request
 * has been answered, so it needs no queue worker. When Pixelforge is rate limiting, at its
 * concurrency limit, overloaded or unreachable, the next attempt is put on the queue with
 * a growing delay, a few times at most. Trying again is safe because the generation's id
 * is the idempotency key.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 8;

    private const MAX_DELAY_SECONDS = 60;

    // Retries are scheduled by this job itself (see retryOrFail), not by the queue.
    public int $tries = 1;

    public function __construct(
        public Generation $generation,
        public int $attempt = 1,
    ) {}

    public function handle(PixelforgeClient $pixelforge, GenerationUpdater $updater): void
    {
        try {
            $provider = $pixelforge->createGeneration($this->generation);
        } catch (PixelforgeException $e) {
            $this->retryOrFail($updater, $e->isRetryable(), $e->retryAfter, $e->errorCode, $e->getMessage());

            return;
        } catch (ConnectionException) {
            $this->retryOrFail($updater, true, null, 'connection_error', 'Pixelforge could not be reached. You were not charged; please try again.');

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

    private function retryOrFail(GenerationUpdater $updater, bool $retryable, ?int $retryAfter, string $errorCode, string $message): void
    {
        if ($retryable && $this->attempt < self::MAX_ATTEMPTS) {
            // Pixelforge's Retry-After (or 2 seconds), doubled on every attempt.
            $delay = min(($retryAfter ?? 2) * 2 ** ($this->attempt - 1), self::MAX_DELAY_SECONDS);

            self::dispatch($this->generation, $this->attempt + 1)->delay($delay);
        } else {
            $updater->failSubmission($this->generation, $errorCode, $message);
        }
    }
}
