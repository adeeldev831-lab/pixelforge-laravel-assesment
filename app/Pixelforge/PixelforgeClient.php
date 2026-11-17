<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\PendingRequest;
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
            return $this->request()->get('pricing')->throw()->json();
        });
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('pixelforge.base_url'))
            ->withToken(config('pixelforge.api_key'))
            ->acceptJson()
            ->timeout(15);
    }
}
