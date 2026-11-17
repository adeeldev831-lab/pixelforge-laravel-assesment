<?php

namespace Tests\Feature\Generations;

use App\Generations\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProviderGeneration;
use Tests\TestCase;

class SyncStaleGenerationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_generation_whose_webhook_never_arrived_is_fetched_and_charged(): void
    {
        $lost = Generation::factory()->queued()->create(['created_at' => now()->subMinutes(5)]);
        $recent = Generation::factory()->queued()->create();
        $finished = Generation::factory()->queued()->create(['created_at' => now()->subMinutes(5), 'status' => GenerationStatus::Failed]);

        Http::fake(["*/generations/{$lost->provider_id}" => Http::response(ProviderGeneration::succeeded($lost))]);

        $this->artisan('generations:sync')->assertSuccessful();

        $this->assertSame(GenerationStatus::Succeeded, $lost->refresh()->status);
        $this->assertSame(ProviderGeneration::CHARGED_NANOS, $lost->charged_nanos);
        $this->assertSame(GenerationStatus::Queued, $recent->refresh()->status);
        $this->assertSame(GenerationStatus::Failed, $finished->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_it_stops_when_pixelforge_is_rate_limiting(): void
    {
        Generation::factory()->queued()->count(3)->create(['created_at' => now()->subMinutes(5)]);
        Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Too many requests.']], 429)]);

        $this->artisan('generations:sync')->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_a_generation_that_never_reached_pixelforge_is_failed_and_releases_its_credits(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $abandoned = Generation::factory()->for($user)->create(['created_at' => now()->subMinutes(20)]);
        $stillSubmitting = Generation::factory()->for($user)->create(['created_at' => now()->subMinutes(2)]);

        $this->artisan('generations:sync')->assertSuccessful();

        $this->assertSame(GenerationStatus::Failed, $abandoned->refresh()->status);
        $this->assertSame('submission_failed', $abandoned->error_code);
        $this->assertSame(0, $abandoned->charged_nanos);
        $this->assertSame(GenerationStatus::Submitting, $stillSubmitting->refresh()->status);
        $this->assertSame($stillSubmitting->estimated_max_nanos, $user->reservedNanos());
    }
}
