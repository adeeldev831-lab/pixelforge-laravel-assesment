<?php

namespace Tests\Feature\Pixelforge;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProviderGeneration;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pixelforge.api_key' => 'pf_test_key']);
    }

    public function test_creating_a_generation_sends_our_id_as_idempotency_key_and_metadata(): void
    {
        $generation = Generation::factory()->create(['reference_images' => ['https://example.com/style.png']]);
        Http::fake(['*/generations' => Http::response(ProviderGeneration::make($generation, 'queued'), 202)]);

        $provider = (new PixelforgeClient)->createGeneration($generation);

        $this->assertSame('queued', $provider['status']);
        Http::assertSent(function (Request $request) use ($generation) {
            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer pf_test_key')
                && $request->hasHeader('Idempotency-Key', $generation->id)
                && $request->data() === [
                    'prompt' => $generation->prompt,
                    'size' => '1024x1024',
                    'quality' => 'medium',
                    'metadata' => ['generation_id' => $generation->id],
                    'reference_images' => ['https://example.com/style.png'],
                ];
        });
    }

    public function test_an_error_response_becomes_an_exception_with_its_code_and_retry_after(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*' => Http::response(
            ['error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many generations.']],
            429,
            ['Retry-After' => '5'],
        )]);

        try {
            (new PixelforgeClient)->createGeneration($generation);
            $this->fail('No exception was thrown.');
        } catch (PixelforgeException $e) {
            $this->assertSame('concurrency_limit_exceeded', $e->errorCode);
            $this->assertSame(5, $e->retryAfter);
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_rejections_that_retrying_cannot_fix_are_not_retryable(): void
    {
        foreach (['content_policy_violation' => 400, 'validation_failed' => 422, 'daily_limit_exceeded' => 429, 'invalid_api_key' => 401] as $code => $status) {
            $this->assertFalse((new PixelforgeException($code, 'Rejected.', $status))->isRetryable(), $code);
        }

        $this->assertTrue((new PixelforgeException('overloaded', 'Busy.', 503))->isRetryable());
        $this->assertTrue((new PixelforgeException('unexpected_response', 'Bad gateway.', 502))->isRetryable());
    }

    public function test_pricing_falls_back_to_the_last_known_copy_when_pixelforge_is_down(): void
    {
        $pricing = json_decode(file_get_contents(base_path('tests/Fixtures/pricing.json')), true);
        Http::fakeSequence('*/pricing')->push($pricing)->push(['error' => ['code' => 'overloaded', 'message' => 'Busy.']], 503);

        $this->assertSame($pricing, (new PixelforgeClient)->pricing());

        $this->travel(6)->minutes();

        $this->assertSame($pricing, (new PixelforgeClient)->pricing());
        Http::assertSentCount(2);
    }

    public function test_pricing_fails_when_pixelforge_is_down_and_nothing_was_ever_fetched(): void
    {
        Http::fake(['*/pricing' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy.']], 503)]);

        $this->expectException(PixelforgeException::class);

        (new PixelforgeClient)->pricing();
    }
}
