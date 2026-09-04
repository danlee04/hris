<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveType;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A month of credits, posted by hand.
 *
 * This is a button rather than a scheduled job. The LAN server has no
 * guaranteed cron, and a scheduler that quietly fails to run produces a month
 * of missing credits nobody notices until somebody files against them. A button
 * that was not pressed is visible on the screen that shows what is still due.
 */
class AccrualPosting
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    /** @return list<array{employee: Employee, ledger: string, days: float, already_posted: bool}> */
    public function preview(string $period): array
    {
        $this->assertPeriod($period);

        $rows = [];

        foreach ($this->accruingTypes() as $type) {
            foreach ($this->eligible($type) as $employee) {
                $rows[] = [
                    'employee' => $employee,
                    'ledger' => $type->ledger,
                    'days' => (float) $type->accrual_days_per_month,
                    'already_posted' => $this->ledger->hasAccrued($employee, $type->ledger, $period),
                ];
            }
        }

        return $rows;
    }

    /** @return int how many entries were written */
    public function post(string $period): int
    {
        $this->assertPeriod($period);

        $written = 0;

        foreach ($this->accruingTypes() as $type) {
            foreach ($this->eligible($type) as $employee) {
                $entry = $this->ledger->accrue(
                    $employee,
                    $type->ledger,
                    (float) $type->accrual_days_per_month,
                    $period
                );

                $written += $entry === null ? 0 : 1;
            }
        }

        return $written;
    }

    /** @return list<array{employee: Employee, ledger: string, days: float, already_posted: bool}> */
    public function previewGrants(string $year): array
    {
        $this->assertYear($year);

        $rows = [];

        foreach ($this->grantingTypes() as $type) {
            foreach ($this->eligible($type) as $employee) {
                $rows[] = [
                    'employee' => $employee,
                    'ledger' => $type->ledger,
                    'days' => (float) $type->grant_days_per_year,
                    'already_posted' => $this->ledger->hasGranted($employee, $type->ledger, $year),
                ];
            }
        }

        return $rows;
    }

    /**
     * The yearly grants: SPL 3, Solo Parent 7, Wellness 5. Separate from the
     * monthly accrual because they are a different event with a different key,
     * and because a job order's only credit arrives this way.
     *
     * @return int how many entries were written
     */
    public function postGrants(string $year): int
    {
        $this->assertYear($year);

        $written = 0;

        foreach ($this->grantingTypes() as $type) {
            foreach ($this->eligible($type) as $employee) {
                $entry = $this->ledger->grant(
                    $employee,
                    $type->ledger,
                    (float) $type->grant_days_per_year,
                    $year
                );

                $written += $entry === null ? 0 : 1;
            }
        }

        return $written;
    }

    /** @return Collection<int, LeaveType> */
    private function accruingTypes(): Collection
    {
        return LeaveType::where('is_active', true)
            ->whereNotNull('accrual_days_per_month')
            ->whereNotNull('ledger')
            ->orderBy('sort_order')
            ->get();
    }

    /** @return Collection<int, LeaveType> */
    private function grantingTypes(): Collection
    {
        return LeaveType::where('is_active', true)
            ->whereNotNull('grant_days_per_year')
            ->whereNotNull('ledger')
            ->orderBy('sort_order')
            ->get();
    }

    /** @return Collection<int, Employee> */
    private function eligible(LeaveType $type): Collection
    {
        return Employee::query()
            ->active()
            ->whereIn('employment_status', $type->applies_to)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    private function assertPeriod(string $period): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException("A period is YYYY-MM; got [{$period}].");
        }
    }

    private function assertYear(string $year): void
    {
        if (preg_match('/^\d{4}$/', $year) !== 1) {
            throw new InvalidArgumentException("A year is YYYY; got [{$year}].");
        }
    }
}
