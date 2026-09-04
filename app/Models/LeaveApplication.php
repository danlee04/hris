<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use Database\Factories\LeaveApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_id', 'leave_type_id', 'date_from', 'date_to', 'days',
    'days_with_pay', 'days_without_pay', 'details', 'commutation',
    'status', 'filed_at', 'decided_at',
])]
class LeaveApplication extends Model
{
    /** @use HasFactory<LeaveApplicationFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'days' => 'float',
            'days_with_pay' => 'float',
            'days_without_pay' => 'float',
            'details' => 'array',
            'status' => LeaveStatus::class,
            'filed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveApproval::class)->orderBy('sequence');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    /** The step it is sitting on, or null when nobody is waiting on it. */
    public function currentApproval(): ?LeaveApproval
    {
        if ($this->status !== LeaveStatus::Pending) {
            return null;
        }

        return $this->approvals()->whereNull('acted_at')->orderBy('sequence')->first();
    }

    public function isPending(): bool
    {
        return $this->status === LeaveStatus::Pending;
    }

    /** Nobody has acted yet, so the applicant may still take it back. */
    public function isUntouched(): bool
    {
        return $this->isPending() && ! $this->approvals()->whereNotNull('acted_at')->exists();
    }
}
