<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use App\Enums\LeaveStep;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'leave_application_id', 'sequence', 'step', 'approver_employee_id',
    'action', 'remarks', 'acted_by_user_id', 'acted_at',
])]
class LeaveApproval extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'step' => LeaveStep::class,
            'action' => ApprovalAction::class,
            'acted_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }

    /** Null for the HR step, which is held by an office rather than a person. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
