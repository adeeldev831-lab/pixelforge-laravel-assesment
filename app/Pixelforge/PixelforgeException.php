<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

/** An error response from Pixelforge, in the shape its API documents. */
class PixelforgeException extends RuntimeException
{
    private const RETRYABLE_CODES = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');

        return new self(
            $response->json('error.code') ?? 'unexpected_response',
            $response->json('error.message') ?? "Pixelforge answered with HTTP {$response->status()}.",
            $response->status(),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /** Nothing was created, and the same request can succeed later. */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE_CODES, true) || $this->status >= 500;
    }
}
