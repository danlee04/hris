<?php

namespace App\Models\Pds;

use App\Models\Employee;
use Database\Factories\Pds\WorkExperienceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'date_from', 'date_to', 'position_title',
    'department_agency', 'monthly_salary', 'salary_grade_step',
    'status_of_appointment', 'is_government_service', 'sort_order',
])]
class WorkExperience extends Model
{
    /** @use HasFactory<WorkExperienceFactory> */
    use HasFactory;

    protected $table = 'pds_work_experiences';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'monthly_salary' => 'decimal:2',
            'is_government_service' => 'boolean',
        ];
    }

    /** The CSC form prints "PRESENT" where this is still running. */
    public function isCurrent(): bool
    {
        return $this->date_to === null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
