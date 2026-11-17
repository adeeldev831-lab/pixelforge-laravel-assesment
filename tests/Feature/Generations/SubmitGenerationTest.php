<?php

namespace Tests\Feature\Generations;

use App\Generations\GenerationStatus;
use App\Generations\GenerationUpdater;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProviderGeneration;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_an_accepted_generation_becomes_queued(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/generations' => Http::response(ProviderGeneration::make($generation, 'queued', ['id' => 'gen_accepted']), 202)]);

        $this->submit($generation);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('gen_accepted', $generation->provider_id);
        Queue::assertNothingPushed();
    }

    public function test_the_concurrency_limit_is_retried_after_the_delay_pixelforge_asks_for(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*' => $this->error(429, 'concurrency_limit_exceeded', ['Retry-After' => '5'])]);

        $this->submit($generation);
        $this->assertRetryQueued(attempt: 2, delay: 5);

        $this->submit($generation, attempt: 3);
        $this->assertRetryQueued(attempt: 4, delay: 20);

        // The delay keeps doubling but never passes a minute.
        $this->submit($generation, attempt: 7);
        $this->assertRetryQueued(attempt: 8, delay: 60);

        $this->assertSame(GenerationStatus::Submitting, $generation->refresh()->status);
    }

    public function test_an_overload_or_a_network_error_is_retried(): void
    {
        $generation = Generation::factory()->create();

        Http::fake(['*' => $this->error(503, 'overloaded', ['Retry-After' => '2'])]);
        $this->submit($generation);
        $this->assertRetryQueued(attempt: 2, delay: 2);

        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('Timed out.'));
        $this->submit($generation, attempt: 2);
        $this->assertRetryQueued(attempt: 3, delay: 4);

        $this->assertSame(GenerationStatus::Submitting, $generation->refresh()->status);
    }

    public function test_it_gives_up_after_the_last_attempt_and_the_generation_fails_for_free(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*' => $this->error(503, 'overloaded', ['Retry-After' => '2'])]);

        $this->submit($generation, attempt: SubmitGeneration::MAX_ATTEMPTS);

        Queue::assertNothingPushed();
        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('overloaded', $generation->error_code);
        $this->assertSame(0, $generation->charged_nanos);
    }

    public function test_a_rejected_prompt_fails_immediately_with_pixelforges_message(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*' => $this->error(400, 'content_policy_violation')]);

        $this->submit($generation);

        Queue::assertNothingPushed();
        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame('Message for content_policy_violation.', $generation->error_message);
        $this->assertSame(0, $generation->charged_nanos);
    }

    public function test_a_generation_pixelforge_accepted_after_we_gave_up_is_still_charged(): void
    {
        $generation = Generation::factory()->create();
        app(GenerationUpdater::class)->failSubmission($generation, 'connection_error', 'Pixelforge could not be reached.');

        $generation = app(GenerationUpdater::class)->apply($generation, ProviderGeneration::succeeded($generation));

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(ProviderGeneration::CHARGED_NANOS, $generation->charged_nanos);
        $this->assertNull($generation->error_code);
    }

    private function submit(Generation $generation, int $attempt = 1): void
    {
        (new SubmitGeneration($generation, $attempt))->handle(app(PixelforgeClient::class), app(GenerationUpdater::class));
    }

    private function assertRetryQueued(int $attempt, int $delay): void
    {
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->attempt === $attempt && $job->delay === $delay);
    }

    private function error(int $status, string $code, array $headers = [])
    {
        return Http::response(['error' => ['type' => 'error', 'code' => $code, 'message' => "Message for {$code}."]], $status, $headers);
    }
}
