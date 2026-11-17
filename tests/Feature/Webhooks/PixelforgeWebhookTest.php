<?php

namespace Tests\Feature\Webhooks;

use App\Generations\GenerationStatus;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ProviderGeneration;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['pixelforge.webhook_secret' => self::SECRET]);
    }

    public function test_a_succeeded_event_records_the_actual_cost(): void
    {
        $generation = Generation::factory()->queued()->create();

        $this->deliver($this->event('evt_1', 'generation.succeeded', ProviderGeneration::succeeded($generation)))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(ProviderGeneration::CHARGED_NANOS, $generation->charged_nanos);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_an_event_delivered_twice_is_recorded_and_applied_once(): void
    {
        $generation = Generation::factory()->queued()->create();
        $event = $this->event('evt_1', 'generation.succeeded', ProviderGeneration::succeeded($generation));

        $this->deliver($event)->assertNoContent();
        $firstUpdate = $generation->refresh()->updated_at;

        $this->travel(5)->seconds();
        $this->deliver($event)->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame(ProviderGeneration::CHARGED_NANOS, $generation->refresh()->charged_nanos);
        $this->assertTrue($firstUpdate->equalTo($generation->updated_at));
    }

    public function test_events_arriving_out_of_order_end_in_the_right_state(): void
    {
        $generation = Generation::factory()->queued()->create();

        $this->deliver($this->event('evt_2', 'generation.succeeded', ProviderGeneration::succeeded($generation)));
        $this->deliver($this->event('evt_1', 'generation.processing', ProviderGeneration::make($generation, 'processing')))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(ProviderGeneration::CHARGED_NANOS, $generation->charged_nanos);
        $this->assertDatabaseCount('webhook_events', 2);
    }

    public function test_a_failed_event_costs_nothing(): void
    {
        $generation = Generation::factory()->queued()->create();

        $this->deliver($this->event('evt_1', 'generation.failed', ProviderGeneration::failed($generation)))->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charged_nanos);
        $this->assertSame('internal_error', $generation->error_code);
    }

    public function test_an_event_that_arrives_before_the_create_response_finds_the_generation_by_metadata(): void
    {
        $generation = Generation::factory()->create();

        $this->deliver($this->event('evt_1', 'generation.processing', ProviderGeneration::make($generation, 'processing', ['id' => 'gen_early'])));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertSame('gen_early', $generation->provider_id);
    }

    public function test_requests_without_a_valid_signature_are_refused(): void
    {
        $generation = Generation::factory()->queued()->create();
        $body = json_encode($this->event('evt_1', 'generation.succeeded', ProviderGeneration::succeeded($generation)));

        $wrongSecret = 't='.time().',v1='.hash_hmac('sha256', time().'.'.$body, 'whsec_wrong');
        $otherBody = $this->signature('{"id":"evt_other"}', time());
        $tooOld = $this->signature($body, time() - 301);

        foreach ([null, 'garbage', $wrongSecret, $otherBody, $tooOld] as $signature) {
            $this->call('POST', '/webhooks/pixelforge', [], [], [], array_filter([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => $signature,
            ]), $body)->assertStatus(400);
        }

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
    }

    public function test_ping_and_unknown_generations_are_acknowledged(): void
    {
        $this->deliver(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-04T14:03:29Z', 'data' => []])->assertNoContent();

        $unknown = ProviderGeneration::succeeded(Generation::factory()->make(['id' => 'missing']));
        $this->deliver($this->event('evt_unknown', 'generation.succeeded', $unknown))->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 2);
    }

    private function event(string $id, string $type, array $generation): array
    {
        return ['id' => $id, 'object' => 'event', 'type' => $type, 'created_at' => '2026-11-04T14:03:29Z', 'data' => ['object' => $generation]];
    }

    private function deliver(array $event): TestResponse
    {
        $body = json_encode($event);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $this->signature($body, time()),
        ], $body);
    }

    private function signature(string $body, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", self::SECRET);
    }
}
