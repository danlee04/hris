<?php

namespace App\Policies;

use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\LeaveApplication;
use App\Models\User;

/**
 * Who may do what to one application.
 *
 * Acting is a policy and not a permission because the question is "are you the
 * approver of THIS application's CURRENT step". A permission cannot see which
 * application is being asked about, and that is how one section head ends up
 * approving another division's leave.
 */
class LeaveApplicationPolicy
{
    /**
     * A sick leave says something about a person's health. The applicant, the
     * people on its chain, and HR.
     */
    public function view(User $user, LeaveApplication $application): bool
    {
        if ($user->can('leave.manage')) {
            return true;
        }

        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return false;
        }

        return $application->employee_id === $employeeId
            || $application->approvals()->where('approver_employee_id', $employeeId)->exists();
    }

    /** The approver of the step it is sitting on, right now. */
    public function act(User $user, LeaveApplication $application): bool
    {
        $current = $application->currentApproval();

        if ($current === null) {
            return false;
        }

        // HR is an office, not a person: whoever holds leave.manage acts, and
        // the person who pressed the button is recorded on the approval.
        if ($current->step === LeaveStep::Hr) {
            return $user->can('leave.manage');
        }

        // Both sides are checked for null. Comparing two nulls would hand the
        // HR step to the first account with no employee record.
        return $current->approver_employee_id !== null
            && $current->approver_employee_id === $user->employee?->id;
    }

    /**
     * The applicant, and only before anybody has signed. Withdrawing after a
     * recommendation is a decision for the person who gave it.
     */
    public function cancel(User $user, LeaveApplication $application): bool
    {
        return $user->employee !== null
            && $application->employee_id === $user->employee->id
            && $application->isUntouched();
    }

    /**
     * Whoever may see it may print it. The form is what gets walked from desk
     * to desk, and refusing to produce it would send the office back to typing.
     */
    public function export(User $user, LeaveApplication $application): bool
    {
        return $this->view($user, $application);
    }

    /** The applicant, and only on one that was sent back to them. */
    public function refile(User $user, LeaveApplication $application): bool
    {
        return $user->employee !== null
            && $application->employee_id === $user->employee->id
            && $application->status === LeaveStatus::Returned;
    }
}
