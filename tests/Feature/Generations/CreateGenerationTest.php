<?php

namespace Tests\Feature\Generations;

use App\Generations\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateGenerationTest extends TestCase
{
    use RefreshDatabase;

    // The worked example from the API docs: a 400-character prompt, high quality, 1536x1024, 2 reference images.
    private const WORKED_EXAMPLE = [
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*/pricing' => Http::response(file_get_contents(base_path('tests/Fixtures/pricing.json')))]);
        Queue::fake();
    }

    public function test_guests_cannot_see_or_create_generations(): void
    {
        $this->get('/generations')->assertRedirect('/login');
        $this->post('/generations', $this->workedExample())->assertRedirect('/login');
    }

    public function test_the_estimate_is_the_provider_cost_range_plus_markup(): void
    {
        $response = $this->actingAs(User::factory()->create())->postJson('/generations/estimate', $this->workedExample());

        // Provider cost is between 0.286225000 and 0.373875000; the user pays 25% more.
        $response->assertOk()->assertExactJson(['min' => '0.357781250', 'max' => '0.467343750']);
    }

    public function test_submitting_stores_the_estimate_and_queues_the_request_to_pixelforge(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', $this->workedExample())->assertRedirect('/generations');

        $generation = $user->generations()->sole();
        $this->assertSame(GenerationStatus::Submitting, $generation->status);
        $this->assertSame(357_781_250, $generation->estimated_min_nanos);
        $this->assertSame(467_343_750, $generation->estimated_max_nanos);
        $this->assertNull($generation->charged_nanos);
        $this->assertCount(2, $generation->reference_images);

        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
    }

    public function test_requests_pixelforge_would_reject_are_rejected_before_anything_is_stored(): void
    {
        $invalid = [
            'prompt' => ['prompt' => str_repeat('a', 4001)],
            'size' => ['size' => '512x512'],
            'quality' => ['quality' => 'ultra'],
            'reference_images' => ['reference_images' => array_fill(0, 5, 'https://example.com/a.png')],
            'reference_images.0' => ['reference_images' => ['ftp://example.com/a.png']],
        ];

        foreach ($invalid as $field => $override) {
            $this->actingAs(User::factory()->create())
                ->post('/generations', $this->workedExample($override))
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_users_only_see_their_own_generations(): void
    {
        $user = User::factory()->create();
        $mine = Generation::factory()->for($user)->create();
        Generation::factory()->create();

        $this->actingAs($user)->get('/generations')->assertInertia(fn ($page) => $page
            ->component('Generations')
            ->has('generations', 1)
            ->where('generations.0.id', $mine->id)
            ->where('generations.0.estimated_max', '0.086332500')
            ->where('generations.0.charged', null)
            ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
            ->where('qualities', ['low', 'medium', 'high']));
    }

    private function workedExample(array $overrides = []): array
    {
        return array_merge(['prompt' => str_repeat('a', 400)], self::WORKED_EXAMPLE, $overrides);
    }
}
