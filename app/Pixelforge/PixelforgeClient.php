<?php

namespace App\Pixelforge;

use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    /**
     * Current rates and token tables. Cached briefly: they rarely change, and the
     * final charge never uses them (it uses the rates on the generation itself).
     *
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', now()->addMinutes(5), function () {
            return $this->send(fn (PendingRequest $http) => $http->get('pricing'));
        });
    }

    /**
     * Asks Pixelforge to start a generation. Our id is the idempotency key, so sending
     * the same generation again returns the first one instead of creating (and billing)
     * a second. It also travels in metadata, which comes back in every webhook.
     *
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(Generation $generation): array
    {
        // Unknown parameters are rejected, so only documented ones are sent.
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'metadata' => ['generation_id' => $generation->id],
        ];

        if ($generation->reference_images !== []) {
            $payload['reference_images'] = $generation->reference_images;
        }

        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $generation->id])
            ->post('generations', $payload));
    }

    /** @return array<string, mixed> the generation object */
    public function generation(string $providerId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get("generations/{$providerId}"));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     *
     * @throws PixelforgeException when Pixelforge answers with an error
     * @throws ConnectionException when it can't be reached
     */
    private function send(callable $call): array
    {
        $response = $call(
            Http::baseUrl(config('pixelforge.base_url'))
                ->withToken(config('pixelforge.api_key'))
                ->acceptJson()
                ->timeout(15)
        );

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }
}
