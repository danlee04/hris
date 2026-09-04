<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveType;

/**
 * Which balances an employee actually has, and what they hold.
 *
 * The list comes from the leave types their employment status may file, so a
 * job order is never shown a vacation balance of zero. A zero they can never
 * fill reads as something to ask HR about.
 */
class LeaveBalance
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    /** @return list<array{ledger: string, label: string, days: float}> */
    public function for(Employee $employee): array
    {
        $status = $employee->employment_status;

        if ($status === null) {
            return [];
        }

        return LeaveType::availableTo($status)
            ->whereNotNull('ledger')
            ->get()
            // Vacation Leave and Mandatory/Forced Leave both spend the vacation
            // balance. Two cards holding the same number is two numbers that
            // can disagree.
            ->unique('ledger')
            ->values()
            ->map(fn (LeaveType $type) => [
                'ledger' => $type->ledger,
                'label' => $this->label($type->ledger),
                'days' => $this->ledger->balance($employee, $type->ledger),
            ])
            ->all();
    }

    public function of(Employee $employee, string $ledger): float
    {
        return $this->ledger->balance($employee, $ledger);
    }

    /**
     * The balance is named for itself, not for the type that spends it.
     * Mandatory/Forced Leave draws on the vacation balance; calling that
     * balance "Mandatory" would be wrong on the form and in the head.
     */
    private function label(string $ledger): string
    {
        return match ($ledger) {
            'vacation' => __('Vacation'),
            'sick' => __('Sick'),
            'spl' => __('Special Privilege'),
            'solo_parent' => __('Solo Parent'),
            'wellness' => __('Wellness'),
            default => $ledger,
        };
    }
}
