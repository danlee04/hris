<?php

namespace App\Services\Leave;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a filled form into an application, its chain and its held credits.
 *
 * All three in one transaction. An application with no approvals is stuck; a
 * hold with no application is credits nobody can get back.
 */
class LeaveFiler
{
    public function __construct(
        private readonly LeaveRoute $route,
        private readonly LeaveLedger $ledger,
    ) {}

    /** @param  array<string, mixed>  $attributes */
    public function file(Employee $applicant, array $attributes): LeaveApplication
    {
        return $this->write($applicant, $attributes, null);
    }

    /**
     * A returned application, corrected and sent again. The chain starts over:
     * the dates may have moved, and a recommendation given for one set of dates
     * does not carry to another.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function refile(LeaveApplication $application, array $attributes): LeaveApplication
    {
        return $this->write($application->employee, $attributes, $application);
    }

    /** @param  array<string, mixed>  $attributes */
    private function write(Employee $applicant, array $attributes, ?LeaveApplication $existing): LeaveApplication
    {
        $type = LeaveType::findOrFail($attributes['leave_type_id']);

        $this->assertAllowed($applicant, $type, $attributes);

        return DB::transaction(function () use ($applicant, $attributes, $type, $existing) {
            // Refused before anything is written: the route throws when a step
            // that applies has nobody to sign it.
            $route = $this->route->for($applicant);

            $split = $this->split($applicant, $type, (float) $attributes['days']);

            $values = [
                'employee_id' => $applicant->id,
                'leave_type_id' => $type->id,
                'date_from' => $attributes['date_from'],
                'date_to' => $attributes['date_to'],
                'days' => (float) $attributes['days'],
                'days_with_pay' => $split['paid'],
                'days_without_pay' => $split['unpaid'],
                'details' => $attributes['details'] ?? null,
                'commutation' => $attributes['commutation'] ?? 'not_requested',
                'status' => LeaveStatus::Pending,
                'filed_at' => now(),
                'decided_at' => null,
            ];

            if ($existing) {
                $existing->update($values);

                // The old chain goes with the old dates. Four stale rows plus
                // four fresh ones would make the chain eight long, and the
                // first unsigned step one nobody is waiting on.
                $existing->approvals()->delete();

                $application = $existing->fresh();
            } else {
                $application = LeaveApplication::create($values);
            }

            foreach ($route as $index => $step) {
                $application->approvals()->create([
                    'sequence' => $index + 1,
                    'step' => $step->step->value,
                    'approver_employee_id' => $step->approver?->id,
                ]);
            }

            if ($type->isCredited() && $split['paid'] > 0) {
                $this->ledger->hold($application, $type->ledger, $split['paid']);
            }

            return $application->fresh(['approvals', 'type']);
        });
    }

    /** @return array{paid: float, unpaid: float} */
    private function split(Employee $applicant, LeaveType $type, float $days): array
    {
        if (! $type->isCredited()) {
            // Maternity leave is a right, not a balance.
            return ['paid' => $days, 'unpaid' => 0.0];
        }

        // The balance already has every pending hold subtracted from it, which
        // is what makes this split right rather than optimistic.
        $available = max(0, $this->ledger->balance($applicant, $type->ledger));

        $paid = min($days, $available);

        return ['paid' => $paid, 'unpaid' => round($days - $paid, 2)];
    }

    /** @param  array<string, mixed>  $attributes */
    private function assertAllowed(Employee $applicant, LeaveType $type, array $attributes): void
    {
        $errors = [];

        if (! $type->is_active || ! in_array($applicant->employment_status?->value, $type->applies_to, true)) {
            $errors['leave_type_id'] = __(':type is not available to :status staff.', [
                'type' => $type->name,
                'status' => $applicant->employment_status?->label() ?? __('this'),
            ]);
        }

        $from = Carbon::parse($attributes['date_from']);
        $to = Carbon::parse($attributes['date_to']);

        if ($to->lt($from)) {
            $errors['date_to'] = __('The last day is before the first.');
        }

        if ((float) $attributes['days'] <= 0) {
            $errors['days'] = __('Say how many days this is for.');
        }

        if ($type->notice_days !== null && $from->startOfDay()->lt(now()->addDays($type->notice_days)->startOfDay())) {
            $errors['date_from'] = __(':type needs :days days of notice.', [
                'type' => $type->name,
                'days' => $type->notice_days,
            ]);
        }

        // Counted on the calendar, which is what "consecutive" means on the
        // form — three consecutive days is not three working days spread over
        // a fortnight.
        if ($type->max_consecutive_days !== null && $from->diffInDays($to) + 1 > $type->max_consecutive_days) {
            $errors['date_to'] = __(':type allows at most :days consecutive days.', [
                'type' => $type->name,
                'days' => $type->max_consecutive_days,
            ]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
