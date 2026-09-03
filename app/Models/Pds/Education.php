<?php

namespace App\Models\Pds;

use App\Enums\EducationLevel;
use App\Models\Employee;
use Database\Factories\Pds\EducationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'level', 'school_name', 'degree_course',
    'period_from', 'period_to', 'highest_level_units', 'year_graduated',
    'honors', 'sort_order',
])]
class Education extends Model
{
    /** @use HasFactory<EducationFactory> */
    use HasFactory;

    protected $table = 'pds_educations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => EducationLevel::class,
            'period_from' => 'integer',
            'period_to' => 'integer',
            'year_graduated' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
