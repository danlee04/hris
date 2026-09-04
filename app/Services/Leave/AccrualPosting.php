<?php

namespace App\Services\Leave;

use App\Enums\LeaveLedgerKind;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            // One query for the whole type, not one per employee. This runs on
            // every keystroke of the month field, against 194 rows.
            $posted = array_flip(
                $this->ledger->postedEmployeeIds($type->ledger, LeaveLedgerKind::Accrual, $period)
            );

            foreach ($this->eligible($type) as $employee) {
                $rows[] = [
                    'employee' => $employee,
                    'ledger' => $type->ledger,
                    'days' => (float) $type->accrual_days_per_month,
                    'already_posted' => isset($posted[$employee->id]),
                ];
            }
        }

        return $rows;
    }

    /**
     * Takes back a month that should not have been posted.
     *
     * @return int how many entries were removed
     *
     * @throws ValidationException if credits from that month have been spent
     */
    public function undo(string $period): int
    {
        $this->assertPeriod($period);

        return $this->remove($period, [LeaveLedgerKind::Accrual]);
    }

    /**
     * Takes back a year's grants, and the forfeits written alongside them.
     * Undoing the grant without the forfeit would leave the balance cleared and
     * nothing to show for it.
     *
     * @return int how many entries were removed
     */
    public function undoGrants(string $year): int
    {
        $this->assertYear($year);

        return $this->remove($year, [LeaveLedgerKind::Grant, LeaveLedgerKind::Expiry]);
    }

    /**
     * @param  list<LeaveLedgerKind>  $kinds
     */
    private function remove(string $period, array $kinds): int
    {
        return DB::transaction(function () use ($period, $kinds) {
            $affected = LeaveLedgerEntry::where('period', $period)
                ->whereIn('kind', array_column($kinds, 'value'))
                ->get(['employee_id', 'ledger'])
                ->unique(fn ($entry) => $entry->employee_id.'|'.$entry->ledger);

            $removed = $this->ledger->removePosting($period, $kinds);

            foreach ($affected as $entry) {
                $employee = Employee::find($entry->employee_id);

                // Nothing spends credits until Phase 2a-2, but by then this is
                // the difference between an undo and somebody owing days they
                // have already taken.
                if ($employee && $this->ledger->balance($employee, $entry->ledger) < 0) {
                    throw ValidationException::withMessages([
                        'period' => __('Those credits have already been used by :name. Correct the balance with an adjustment instead.', [
                            'name' => $employee->fullName(),
                        ]),
                    ]);
                }
            }

            return $removed;
        });
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
            $posted = array_flip(
                $this->ledger->postedEmployeeIds($type->ledger, LeaveLedgerKind::Grant, $year)
            );

            foreach ($this->eligible($type) as $employee) {
                $rows[] = [
                    'employee' => $employee,
                    'ledger' => $type->ledger,
                    'days' => (float) $type->grant_days_per_year,
                    'already_posted' => isset($posted[$employee->id]),
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
                // Asked before the forfeit, not after. The forfeit and the
                // grant are one act: pressing the button twice would otherwise
                // clear the balance the first press granted and hand back
                // nothing, because the grant is the half that refuses to repeat.
                if ($this->ledger->hasGranted($employee, $type->ledger, $year)) {
                    continue;
                }

                // Forfeit first, then grant. Special Privilege Leave is three
                // days a year and does not carry; granting on top of last
                // year's leftovers would hand out six.
                if (! $type->grant_carries_over) {
                    $this->ledger->expire($employee, $type->ledger, $year);
                }

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
