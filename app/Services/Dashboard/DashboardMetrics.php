<?php

namespace App\Services\Dashboard;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use Illuminate\Support\Carbon;

/**
 * The numbers the dashboard draws.
 *
 * They live here rather than in the page because a figure that cannot be tested
 * without a browser is a figure nobody checks. Every one of these counts only
 * active employees: a resigned nurse is still a row, and a headcount that
 * includes her is a headcount HR cannot reconcile with anything.
 */
class DashboardMetrics
{
    /** How many months of the trend to draw, and how much history earns it. */
    private const TREND_MONTHS = 6;

    public function activeEmployees(): int
    {
        return Employee::where('is_active', true)->count();
    }

    /**
     * Headcount per division, largest first.
     *
     * The division is read through the section when the employee has none of
     * their own. The real import fills section_id and leaves division_id null —
     * the section is the assignment, and the division is wherever that section
     * sits. Counting the column alone would draw an empty hospital.
     *
     * @return list<array{label: string, count: int}>
     */
    public function headcountByDivision(): array
    {
        // Grouped in PHP rather than in SQL. The coalesce across two tables
        // needs a raw join expression to say in SQL, and this is 134 rows once
        // per page load — the readable version is the right one here.
        return Employee::where('is_active', true)
            ->with('section.division', 'division')
            ->get()
            ->groupBy(fn (Employee $employee) => $employee->division?->name
                ?? $employee->section?->division?->name
                // Named rather than dropped: bars that quietly add up to less
                // than the headcount beside them raise a question that nothing
                // on the page answers.
                ?? __('Unassigned'))
            ->map->count()
            ->sortDesc()
            ->map(fn (int $count, string $name) => ['label' => $name, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * The workforce split by employment status, in the enum's own order.
     *
     * A status with nobody in it is left out. Coterminous appointments are real
     * at DTRC but rare, and an empty segment is a legend entry that explains
     * nothing.
     *
     * @return list<array{label: string, count: int}>
     */
    public function headcountByStatus(): array
    {
        $counts = Employee::where('is_active', true)
            ->selectRaw('employment_status, count(*) as total')
            ->groupBy('employment_status')
            ->pluck('total', 'employment_status');

        $out = [];

        foreach (EmploymentStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);

            if ($count > 0) {
                $out[] = ['label' => $status->label(), 'count' => $count];
            }
        }

        return $out;
    }

    /**
     * Active employees with nothing at all in the ledger.
     *
     * The number that has to reach zero before leave is more than a form: with
     * an empty ledger every balance reads zero, every application is leave
     * without pay, and nothing on the way through says so.
     */
    public function withoutLeaveCredits(): int
    {
        return Employee::where('is_active', true)
            ->whereDoesntHave('leaveLedgerEntries')
            ->count();
    }

    /** Active employees with no account, who therefore cannot file anything. */
    public function withoutLogin(): int
    {
        return Employee::where('is_active', true)
            ->whereNull('user_id')
            ->count();
    }

    /**
     * Applications filed per month, oldest first, zero-filled.
     *
     * Every month in the window gets a point even when nothing was filed. A
     * month left out would close the gap between its neighbours and draw a
     * climb that never happened.
     *
     * @return list<array{label: string, month: string, count: int}>
     */
    public function leaveTrend(int $months = self::TREND_MONTHS): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $filed = LeaveApplication::query()
            ->where('filed_at', '>=', $start)
            ->get(['filed_at'])
            ->groupBy(fn (LeaveApplication $application) => $application->filed_at->format('Y-m'))
            ->map->count();

        $out = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);

            $out[] = [
                'label' => $month->format('M'),
                'month' => $month->format('Y-m'),
                'count' => (int) ($filed[$month->format('Y-m')] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Whether anything was filed in the window the trend covers.
     *
     * The chart is drawn from the first application onward rather than waiting
     * for a full six months of history. An axis with a baseline, real zeros and
     * a curve reads as quiet months; the empty-axis problem that argued for
     * hiding it was a problem of missing context, and the context is there now.
     * A chart nobody renders for half a year is a chart nobody knows is right.
     */
    public function hasLeaveToShow(): bool
    {
        return LeaveApplication::where(
            'filed_at',
            '>=',
            Carbon::now()->startOfMonth()->subMonths(self::TREND_MONTHS - 1)
        )->exists();
    }
}
