<?php

namespace App\Services\Leave;

use App\Enums\ApprovalAction;
use App\Enums\LeaveStatus;
use App\Models\LeaveApplication;
use App\Models\LeaveApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What an approver's action does to an application and to the ledger.
 *
 * Whether this person may act at all is a policy question, asked before this is
 * reached. What is asked here is whether the application is in a state where
 * anybody may act.
 */
class LeaveDecision
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    public function act(
        LeaveApplication $application,
        LeaveApproval $approval,
        ApprovalAction $action,
        ?string $remarks,
    ): LeaveApplication {
        $this->assertActionable($application, $approval);

        if ($action !== ApprovalAction::Approve && trim((string) $remarks) === '') {
            // A refusal a person cannot answer is one they will ask about in
            // the corridor instead.
            throw ValidationException::withMessages([
                'remarks' => __('Say why. The applicant sees this.'),
            ]);
        }

        return DB::transaction(function () use ($application, $approval, $action, $remarks) {
            $approval->update([
                'action' => $action->value,
                'remarks' => $remarks === null ? null : trim($remarks),
                'acted_by_user_id' => auth()->id(),
                'acted_at' => now(),
            ]);

            $application = $application->fresh();

            if ($action === ApprovalAction::Disapprove) {
                return $this->end($application, LeaveStatus::Disapproved);
            }

            if ($action === ApprovalAction::Return) {
                return $this->end($application, LeaveStatus::Returned);
            }

            // Approved. If somebody is still waiting on it, it stays pending.
            if ($application->currentApproval() !== null) {
                return $application;
            }

            $this->ledger->commitFor($application);

            $application->update([
                'status' => LeaveStatus::Approved,
                'decided_at' => now(),
            ]);

            return $application->fresh();
        });
    }

    /** The applicant taking it back, before anybody has signed. */
    public function cancel(LeaveApplication $application): LeaveApplication
    {
        if (! $application->isUntouched()) {
            throw ValidationException::withMessages([
                'status' => __('Somebody has already acted on this. Ask them to return or disapprove it.'),
            ]);
        }

        return DB::transaction(fn () => $this->end($application, LeaveStatus::Cancelled));
    }

    private function end(LeaveApplication $application, LeaveStatus $status): LeaveApplication
    {
        // The credits go back whatever the reason it ended.
        $this->ledger->releaseFor($application);

        $application->update([
            'status' => $status,
            'decided_at' => now(),
        ]);

        return $application->fresh();
    }

    private function assertActionable(LeaveApplication $application, LeaveApproval $approval): void
    {
        $current = $application->currentApproval();

        if ($current === null) {
            throw ValidationException::withMessages([
                'status' => __('This application has already been decided.'),
            ]);
        }

        // The division head cannot sign before the section head has, or the
        // order printed on the form means nothing.
        if ($current->id !== $approval->id) {
            throw ValidationException::withMessages([
                'status' => __('This application is waiting on :step.', [
                    'step' => $current->step->label(),
                ]),
            ]);
        }
    }
}
