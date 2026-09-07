<?php

namespace App\Services\Leave;

use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\LeaveApplication;
use App\Models\LeaveApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What is sitting on one person's desk.
 *
 * Extracted from the Approvals screen so the dashboard can count the same
 * thing. The rule is subtle enough that two copies would drift: three of the
 * four steps belong to a named person frozen at filing, and the HR step belongs
 * to an office, so anyone holding `leave.manage` answers for it.
 */
class ApprovalQueue
{
    /**
     * The applications whose *current* step belongs to this person.
     *
     * Two steps rather than one correlated subquery: find the first unsigned
     * approval of every pending application, then keep the ones that are this
     * person's. Written the plain way because a query nobody can read is a
     * query nobody can fix, and this list is at most a few hundred rows.
     */
    public function waitingFor(?User $user): Builder
    {
        if ($user === null) {
            return LeaveApplication::query()->whereRaw('1 = 0');
        }

        $employeeId = $user->employee?->id;
        $isHr = $user->can('leave.manage');

        $applicationIds = LeaveApproval::query()
            ->whereNull('acted_at')
            ->whereHas('application', fn ($q) => $q->where('status', LeaveStatus::Pending))
            ->get(['id', 'leave_application_id', 'sequence', 'step', 'approver_employee_id'])
            ->groupBy('leave_application_id')
            ->map(fn ($approvals) => $approvals->sortBy('sequence')->first())
            ->filter(fn ($approval) => $approval->approver_employee_id !== null
                ? $approval->approver_employee_id === $employeeId
                : ($isHr && $approval->step === LeaveStep::Hr))
            ->pluck('leave_application_id');

        return LeaveApplication::query()
            ->whereIn('id', $applicationIds)
            ->with(['employee', 'type', 'approvals.approver'])
            ->latest('filed_at');
    }

    public function countFor(?User $user): int
    {
        return $this->waitingFor($user)->count();
    }
}
