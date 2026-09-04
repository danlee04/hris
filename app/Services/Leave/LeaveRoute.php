<?php

namespace App\Services\Leave;

use App\Enums\LeaveStep;
use App\Models\Employee;
use Illuminate\Validation\ValidationException;

/**
 * The chain an application walks, derived from the organizational chart.
 *
 * The HR office described four routes; they are one rule. The full chain is
 * section head, HR, division head, Chief, and every step the applicant
 * themselves would sign is removed. Nobody recommends their own leave.
 *
 * Nothing is stored. `sections.section_head_employee_id`,
 * `divisions.division_head_employee_id` and `employees.is_chief_of_hospital`
 * are the chart, and a second table of approvers would drift away from it.
 */
class LeaveRoute
{
    /** @return list<RouteStep> */
    public function for(Employee $applicant): array
    {
        $applicant->loadMissing(['section.division', 'division']);

        $division = $applicant->section?->division ?? $applicant->division;

        $candidates = [
            // A step applies only if the applicant sits in that unit. A
            // division head is in no section, so there is no section head above
            // them to skip.
            [
                LeaveStep::SectionHead,
                $applicant->section !== null,
                fn () => $applicant->section?->head,
                fn () => __('No section head is recorded for :name.', ['name' => $applicant->section?->name]),
            ],
            [LeaveStep::Hr, true, fn () => null, fn () => ''],
            [
                LeaveStep::DivisionHead,
                $division !== null,
                fn () => $division?->head,
                fn () => __('No division head is recorded for :name.', ['name' => $division?->name]),
            ],
            [
                LeaveStep::Chief,
                true,
                fn () => Employee::where('is_chief_of_hospital', true)->first(),
                fn () => __('No Chief of Hospital is recorded.'),
            ],
        ];

        $route = [];

        foreach ($candidates as [$step, $applies, $resolve, $message]) {
            if (! $applies) {
                continue;
            }

            if ($step === LeaveStep::Hr) {
                $route[] = new RouteStep($step, null);

                continue;
            }

            $approver = $resolve();

            if ($approver === null) {
                // Refused, not skipped. An application that reached the Chief
                // without a recommendation would look complete on the way.
                throw ValidationException::withMessages([
                    'leave_type_id' => $message().' '.__('Ask HR to set one before filing.'),
                ]);
            }

            // Nobody signs their own leave — including a section head who also
            // leads the division, who would otherwise be asked twice.
            if ($approver->id === $applicant->id) {
                continue;
            }

            $route[] = new RouteStep($step, $approver);
        }

        return $route;
    }
}
