<?php

namespace Tests\Feature\Generations;

use App\Generations\GenerationStatus;
use App\Generations\GenerationUpdater;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProviderGeneration;
use Tests\TestCase;

class GenerationUpdaterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_succeeded_generation_is_charged_the_provider_cost_plus_markup(): void
    {
        $generation = Generation::factory()->queued()->create();

        $generation = $this->updater()->apply($generation, ProviderGeneration::succeeded($generation));

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(ProviderGeneration::PROVIDER_COST_NANOS, $generation->provider_cost_nanos);
        $this->assertSame(ProviderGeneration::CHARGED_NANOS, $generation->charged_nanos);
        $this->assertSame(ProviderGeneration::USAGE, $generation->usage);
        $this->assertSame('2026-11-04 14:03:29', $generation->completed_at->toDateTimeString());
        $this->assertNotNull($generation->image_url);
    }

    public function test_the_charge_uses_the_rates_on_the_generation(): void
    {
        $generation = Generation::factory()->queued()->create();

        $generation = $this->updater()->apply($generation, ProviderGeneration::succeeded($generation, [
            'rates' => ['input_text_tokens' => '10.00', 'input_image_tokens' => '20.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '25.00'],
        ]));

        $this->assertSame(2 * ProviderGeneration::PROVIDER_COST_NANOS, $generation->provider_cost_nanos);
    }

    public function test_a_failed_generation_costs_nothing(): void
    {
        $generation = Generation::factory()->queued()->create();

        $generation = $this->updater()->apply($generation, ProviderGeneration::failed($generation));

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('internal_error', $generation->error_code);
        $this->assertSame(0, $generation->charged_nanos);
        $this->assertSame(0, $generation->provider_cost_nanos);
    }

    public function test_a_late_processing_update_never_moves_a_finished_generation_back(): void
    {
        $generation = Generation::factory()->queued()->create();
        $this->updater()->apply($generation, ProviderGeneration::succeeded($generation));

        $generation = $this->updater()->apply($generation, ProviderGeneration::make($generation, 'processing'));

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(ProviderGeneration::CHARGED_NANOS, $generation->charged_nanos);
    }

    public function test_a_repeated_or_conflicting_final_update_changes_nothing(): void
    {
        $generation = Generation::factory()->queued()->create();
        $this->updater()->apply($generation, ProviderGeneration::succeeded($generation));

        $this->updater()->apply($generation, ProviderGeneration::succeeded($generation, [
            'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 1, 'output_tokens' => 1, 'reasoning_tokens' => 1, 'total_tokens' => 4],
        ]));
        $generation = $this->updater()->apply($generation, ProviderGeneration::failed($generation));

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(ProviderGeneration::CHARGED_NANOS, $generation->charged_nanos);
        $this->assertNull($generation->error_code);
    }

    public function test_an_update_that_arrives_before_the_create_response_is_applied(): void
    {
        $generation = Generation::factory()->create();
        $this->assertNull($generation->provider_id);

        $generation = $this->updater()->apply($generation, ProviderGeneration::make($generation, 'processing', ['id' => 'gen_early']));

        $this->assertSame('gen_early', $generation->provider_id);
        $this->assertSame(GenerationStatus::Processing, $generation->status);

        // The create response then shows it as queued, which is behind where we are.
        $generation = $this->updater()->apply($generation, ProviderGeneration::make($generation, 'queued', ['id' => 'gen_early']));

        $this->assertSame(GenerationStatus::Processing, $generation->status);
    }

    public function test_a_rejected_submission_fails_the_generation_at_no_cost(): void
    {
        $generation = Generation::factory()->create();

        $generation = $this->updater()->failSubmission($generation, 'content_policy_violation', 'The prompt was rejected.');

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(0, $generation->charged_nanos);
        $this->assertNotNull($generation->completed_at);
    }

    private function updater(): GenerationUpdater
    {
        return app(GenerationUpdater::class);
    }
}
