<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'name', 'legal_basis', 'ledger', 'accrual_days_per_month',
    'grant_days_per_year', 'grant_carries_over', 'notice_days', 'max_consecutive_days',
    'applies_to', 'sort_order', 'is_active',
])]
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'applies_to' => 'array',
            'grant_days_per_year' => 'integer',
            'notice_days' => 'integer',
            'max_consecutive_days' => 'integer',
            'grant_carries_over' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** A type with no ledger is applied for and approved but spends nothing. */
    public function isCredited(): bool
    {
        return $this->ledger !== null;
    }

    /**
     * What this employment status may file. Job order and contract of service
     * staff earn no statutory credits; offering them Vacation Leave would offer
     * 37 people days that do not exist.
     */
    public function scopeAvailableTo(Builder $query, EmploymentStatus $status): void
    {
        $query->where('is_active', true)
            ->whereJsonContains('applies_to', $status->value)
            ->orderBy('sort_order');
    }
}
