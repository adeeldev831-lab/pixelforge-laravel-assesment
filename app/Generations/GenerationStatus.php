<?php

namespace App\Generations;

enum GenerationStatus: string
{
    // Saved locally, not yet accepted by Pixelforge.
    case Submitting = 'submitting';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * A generation only ever moves forward. Events can arrive late or twice, so an
     * update is applied only when it is a step ahead of where the generation is now.
     */
    public function canMoveTo(self $next): bool
    {
        return ! $this->isFinal() && $next->step() > $this->step();
    }

    private function step(): int
    {
        return match ($this) {
            self::Submitting => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
