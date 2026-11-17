<?php

namespace Tests\Feature\Billing;

use App\Generations\GenerationStatus;
use App\Generations\GenerationUpdater;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProviderGeneration;
use Tests\TestCase;

class CreditsTest extends TestCase
{
    use RefreshDatabase;

    // Costs the user between 0.357781250 and 0.467343750 (the worked example from the API docs, plus 25%).
    private const REQUEST = [
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ];

    private const MAX_PRICE_NANOS = 467_343_750;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*/pricing' => Http::response(file_get_contents(base_path('tests/Fixtures/pricing.json')))]);
        Queue::fake();
    }

    public function test_buying_credits_adds_them_to_the_balance(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['dollars' => 10])->assertRedirect();
        $this->actingAs($user)->post('/credits', ['dollars' => 5])->assertRedirect();

        $this->assertSame(15_000_000_000, $user->balanceNanos());
        $this->assertSame(15_000_000_000, $user->availableNanos());
    }

    public function test_only_the_offered_credit_packs_can_be_bought(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['dollars' => 1_000_000])->assertSessionHasErrors('dollars');
        $this->actingAs($user)->post('/credits', ['dollars' => -5])->assertSessionHasErrors('dollars');

        $this->assertSame(0, $user->balanceNanos());
    }

    public function test_a_user_who_cannot_afford_the_maximum_price_cannot_generate(): void
    {
        $user = User::factory()->create();
        $this->addCredits($user, self::MAX_PRICE_NANOS - 1);

        $this->actingAs($user)->post('/generations', $this->request())->assertSessionHasErrors('balance');
        $this->assertSame(0, $user->generations()->count());
        Queue::assertNothingPushed();

        $this->addCredits($user, 1);

        $this->actingAs($user)->post('/generations', $this->request())->assertSessionHasNoErrors();
        $this->assertSame(1, $user->generations()->count());
    }

    public function test_a_running_generation_holds_its_maximum_price(): void
    {
        $user = User::factory()->create();
        $this->addCredits($user, self::MAX_PRICE_NANOS * 2 - 1);

        $this->actingAs($user)->post('/generations', $this->request())->assertSessionHasNoErrors();

        $this->assertSame(self::MAX_PRICE_NANOS, $user->reservedNanos());
        $this->assertSame(self::MAX_PRICE_NANOS - 1, $user->availableNanos());

        // The balance would cover a second one, but not while the first is still running.
        $this->actingAs($user)->post('/generations', $this->request())->assertSessionHasErrors('balance');
        $this->actingAs($user)->postJson('/generations/estimate', $this->request())->assertJsonPath('affordable', false);
    }

    public function test_a_succeeded_generation_is_charged_once_and_releases_what_it_held(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $generation = Generation::factory()->for($user)->queued()->create(['estimated_max_nanos' => self::MAX_PRICE_NANOS]);
        $this->assertSame(1_000_000_000 - self::MAX_PRICE_NANOS, $user->availableNanos());

        $updater = app(GenerationUpdater::class);
        $updater->apply($generation, ProviderGeneration::succeeded($generation));
        $updater->apply($generation, ProviderGeneration::succeeded($generation));

        $this->assertSame(1, CreditTransaction::where('type', CreditTransaction::CHARGE)->count());
        $this->assertSame(0, $user->reservedNanos());
        $this->assertSame(1_000_000_000 - ProviderGeneration::CHARGED_NANOS, $user->balanceNanos());
    }

    public function test_a_failed_generation_gives_back_everything_it_held(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $generation = Generation::factory()->for($user)->queued()->create();

        app(GenerationUpdater::class)->apply($generation, ProviderGeneration::failed($generation));

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame(1_000_000_000, $user->availableNanos());
        $this->assertSame(0, CreditTransaction::where('type', CreditTransaction::CHARGE)->count());
    }

    private function request(): array
    {
        return ['prompt' => str_repeat('a', 400)] + self::REQUEST;
    }

    private function addCredits(User $user, int $nanos): void
    {
        $user->creditTransactions()->create(['type' => CreditTransaction::PURCHASE, 'amount_nanos' => $nanos]);
    }
}
