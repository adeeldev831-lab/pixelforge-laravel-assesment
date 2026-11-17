<?php

namespace App\Http\Controllers;

use App\Billing\Money;
use App\Generations\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What users were charged, what Pixelforge charged us and the difference, for each
 * UTC day and each user. A generation counts on the day Pixelforge finished it, because
 * that is the day Pixelforge bills it.
 */
class AdminReportController extends Controller
{
    private const DAYS = 30;

    public function __invoke(PixelforgeClient $pixelforge): Response
    {
        $end = now('UTC')->startOfDay();
        $start = $end->copy()->subDays(self::DAYS - 1);

        // One row per day and user. Only succeeded generations cost anything.
        $rows = Generation::query()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('status', GenerationStatus::Succeeded)
            ->where('completed_at', '>=', $start)
            ->groupByRaw('DATE(completed_at), users.id, users.email')
            ->orderByRaw('DATE(completed_at) DESC, users.email')
            ->selectRaw('DATE(completed_at) as date, users.email as user, COUNT(*) as generations')
            ->selectRaw('SUM(charged_nanos) as charged, SUM(provider_cost_nanos) as provider_cost')
            ->toBase()
            ->get();

        $pixelforgeCosts = $this->pixelforgeCostsByDate($pixelforge, $start->toDateString(), $end->toDateString());

        $days = $rows->groupBy('date')->map(function (Collection $dayRows, string $date) use ($pixelforgeCosts) {
            $pixelforgeCost = $pixelforgeCosts[$date] ?? null;

            return [
                'date' => $date,
                ...$this->totals($dayRows),
                'pixelforge_cost' => $pixelforgeCost === null ? null : Money::toDecimal($pixelforgeCost),
                'matches_pixelforge' => $pixelforgeCost === null ? null : $pixelforgeCost === (int) $dayRows->sum('provider_cost'),
            ];
        });

        return Inertia::render('AdminReport', [
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'total' => $this->totals($rows),
            'days' => $days->values(),
            'userDays' => $rows->map(fn (object $row) => [
                'date' => $row->date,
                'user' => $row->user,
                ...$this->totals(collect([$row])),
            ]),
        ]);
    }

    /** @return array{generations: int, charged: string, provider_cost: string, profit: string} */
    private function totals(Collection $rows): array
    {
        $charged = (int) $rows->sum('charged');
        $providerCost = (int) $rows->sum('provider_cost');

        return [
            'generations' => (int) $rows->sum('generations'),
            'charged' => Money::toDecimal($charged),
            'provider_cost' => Money::toDecimal($providerCost),
            'profit' => Money::toDecimal($charged - $providerCost),
        ];
    }

    /**
     * Pixelforge's own figure for each day, in nanodollars, to check ours against.
     * Null when it can't be fetched; the report still shows our numbers.
     *
     * @return array<string, int>|null
     */
    private function pixelforgeCostsByDate(PixelforgeClient $pixelforge, string $start, string $end): ?array
    {
        try {
            return collect($pixelforge->usage($start, $end))
                ->mapWithKeys(fn (array $day) => [$day['date'] => Money::fromDecimal($day['cost_usd'])])
                ->all();
        } catch (PixelforgeException|ConnectionException) {
            return null;
        }
    }
}
