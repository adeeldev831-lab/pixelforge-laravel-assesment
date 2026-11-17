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
     * If Pixelforge can't be reached, the last copy we saw is used, so the page
     * still loads and estimates still work during an outage.
     *
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', now()->addMinutes(5), function () {
            try {
                $pricing = $this->send(fn (PendingRequest $http) => $http->get('pricing'));
            } catch (PixelforgeException|ConnectionException $e) {
                return Cache::get('pixelforge.pricing.last_known') ?? throw $e;
            }

            Cache::forever('pixelforge.pricing.last_known', $pricing);

            return $pricing;
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
     * What Pixelforge billed this API key per UTC day, both dates inclusive.
     *
     * @return list<array<string, mixed>> one entry per day
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]))['data'];
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
