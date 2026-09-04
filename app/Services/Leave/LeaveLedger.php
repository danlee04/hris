<?php

namespace App\Services\Leave;

use App\Enums\LeaveLedgerKind;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only class that writes a ledger entry.
 *
 * Balances stay correct because there is exactly one place that can change
 * them. Every other part of the leave system asks this one, and this one is
 * tested.
 */
class LeaveLedger
{
    /** A balance is the sum of its entries. Nothing stores it. */
    public function balance(Employee $employee, string $ledger): float
    {
        return (float) LeaveLedgerEntry::where('employee_id', $employee->id)
            ->where('ledger', $ledger)
            ->sum('days');
    }

    /**
     * What HR carried in from the spreadsheet. Once only — a second one is a
     * correction, and a correction is an adjustment, which has to say why.
     */
    public function open(Employee $employee, string $ledger, float $days, ?Carbon $on = null): LeaveLedgerEntry
    {
        $exists = LeaveLedgerEntry::where('employee_id', $employee->id)
            ->where('ledger', $ledger)
            ->where('kind', LeaveLedgerKind::Opening)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'days' => __('An opening balance is already recorded. Use an adjustment to correct it.'),
            ]);
        }

        return $this->write($employee, $ledger, LeaveLedgerKind::Opening, $days, $on, null, __('Opening balance'));
    }

    /**
     * One month's credits. Returns null when the month is already posted,
     * which is what makes the posting button safe to press twice.
     */
    public function accrue(Employee $employee, string $ledger, float $days, string $period): ?LeaveLedgerEntry
    {
        return $this->writeOnce($employee, $ledger, LeaveLedgerKind::Accrual, $days, $period,
            __('Credits for :period', ['period' => $period]));
    }

    /** One year's grant. Same idempotency, keyed on the year. */
    public function grant(Employee $employee, string $ledger, float $days, string $period): ?LeaveLedgerEntry
    {
        return $this->writeOnce($employee, $ledger, LeaveLedgerKind::Grant, $days, $period,
            __('Grant for :period', ['period' => $period]));
    }

    /** A correction, which must say what it is correcting. */
    public function adjust(Employee $employee, string $ledger, float $days, string $reason): LeaveLedgerEntry
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => __('Say why the balance is being adjusted.'),
            ]);
        }

        return $this->write($employee, $ledger, LeaveLedgerKind::Adjustment, $days, null, null, trim($reason));
    }

    private function writeOnce(
        Employee $employee,
        string $ledger,
        LeaveLedgerKind $kind,
        float $days,
        string $period,
        string $description,
    ): ?LeaveLedgerEntry {
        $exists = LeaveLedgerEntry::where('employee_id', $employee->id)
            ->where('ledger', $ledger)
            ->where('kind', $kind)
            ->where('period', $period)
            ->exists();

        if ($exists) {
            return null;
        }

        // The check above loses a race; the unique index does not. Two people
        // pressing Post at the same moment must not produce two accruals.
        try {
            return $this->write($employee, $ledger, $kind, $days, null, $period, $description);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function write(
        Employee $employee,
        string $ledger,
        LeaveLedgerKind $kind,
        float $days,
        ?Carbon $on,
        ?string $period,
        string $description,
    ): LeaveLedgerEntry {
        return DB::transaction(fn () => LeaveLedgerEntry::create([
            'employee_id' => $employee->id,
            'ledger' => $ledger,
            'kind' => $kind->value,
            'days' => $days,
            'effective_date' => $on ?? now(),
            'period' => $period,
            'description' => $description,
            'created_by_user_id' => auth()->id(),
        ]));
    }
}
