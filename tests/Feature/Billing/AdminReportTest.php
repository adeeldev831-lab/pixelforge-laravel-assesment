<?php

namespace Tests\Feature\Billing;

use App\Generations\GenerationUpdater;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProviderGeneration;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pixelforge.admin_emails' => ['admin@example.com']]);
        $this->travelTo('2026-11-05 12:00:00');
    }

    public function test_only_operators_can_open_the_report(): void
    {
        Http::fake(['*/usage*' => Http::response(['data' => []])]);

        $this->get('/admin/report')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/report')->assertOk();
    }

    public function test_it_reports_spend_cost_and_profit_per_day_and_user_on_the_day_pixelforge_finished(): void
    {
        $alice = User::factory()->create(['email' => 'alice@example.com']);
        $bob = User::factory()->create(['email' => 'bob@example.com']);

        // Created on the 3rd but finished just after midnight UTC: Pixelforge bills it on the 4th.
        $this->succeed($alice, completedAt: '2026-11-04T00:00:01Z', createdAt: '2026-11-03 23:59:50');
        $this->succeed($alice, completedAt: '2026-11-04T14:03:29Z');
        $this->succeed($bob, completedAt: '2026-11-04T23:59:59Z');
        $this->succeed($bob, completedAt: '2026-11-05T09:00:00Z');
        $failed = Generation::factory()->for($bob)->queued()->create();
        app(GenerationUpdater::class)->apply($failed, ProviderGeneration::failed($failed));

        // Pixelforge agrees about the 4th (3 x 0.305510000) but not about the 5th.
        Http::fake(['*/usage*' => Http::response(['data' => [
            ['date' => '2026-11-04', 'generations' => 3, 'cost_usd' => '0.916530000'],
            ['date' => '2026-11-05', 'generations' => 2, 'cost_usd' => '0.611020000'],
        ]])]);

        $this->actingAs($this->admin())->get('/admin/report')->assertInertia(fn ($page) => $page
            ->component('AdminReport')
            ->where('total', ['generations' => 4, 'charged' => '1.527550000', 'provider_cost' => '1.222040000', 'profit' => '0.305510000'])
            ->where('days', [
                [
                    'date' => '2026-11-05', 'generations' => 1, 'charged' => '0.381887500', 'provider_cost' => '0.305510000', 'profit' => '0.076377500',
                    'pixelforge_cost' => '0.611020000', 'matches_pixelforge' => false,
                ],
                [
                    'date' => '2026-11-04', 'generations' => 3, 'charged' => '1.145662500', 'provider_cost' => '0.916530000', 'profit' => '0.229132500',
                    'pixelforge_cost' => '0.916530000', 'matches_pixelforge' => true,
                ],
            ])
            ->where('userDays', [
                ['date' => '2026-11-05', 'user' => 'bob@example.com', 'generations' => 1, 'charged' => '0.381887500', 'provider_cost' => '0.305510000', 'profit' => '0.076377500'],
                ['date' => '2026-11-04', 'user' => 'alice@example.com', 'generations' => 2, 'charged' => '0.763775000', 'provider_cost' => '0.611020000', 'profit' => '0.152755000'],
                ['date' => '2026-11-04', 'user' => 'bob@example.com', 'generations' => 1, 'charged' => '0.381887500', 'provider_cost' => '0.305510000', 'profit' => '0.076377500'],
            ]));
    }

    public function test_the_report_still_loads_when_pixelforge_usage_is_unavailable(): void
    {
        $this->succeed(User::factory()->create(), completedAt: '2026-11-04T14:03:29Z');
        Http::fake(['*/usage*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy.']], 503)]);

        $this->actingAs($this->admin())->get('/admin/report')->assertInertia(fn ($page) => $page
            ->where('days.0.provider_cost', '0.305510000')
            ->where('days.0.pixelforge_cost', null)
            ->where('days.0.matches_pixelforge', null));
    }

    private function admin(): User
    {
        return User::factory()->create(['email' => 'admin@example.com']);
    }

    private function succeed(User $user, string $completedAt, ?string $createdAt = null): void
    {
        $generation = Generation::factory()->for($user)->queued()->create(['created_at' => $createdAt ?? now()]);

        app(GenerationUpdater::class)->apply($generation, ProviderGeneration::succeeded($generation, ['completed_at' => $completedAt]));
    }
}
