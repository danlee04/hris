<?php

namespace App\Models\Pds;

use App\Enums\LearningDevelopmentType;
use App\Models\Employee;
use Database\Factories\Pds\LearningDevelopmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'title', 'date_from', 'date_to',
    'number_of_hours', 'type', 'conducted_by', 'sort_order',
])]
class LearningDevelopment extends Model
{
    /** @use HasFactory<LearningDevelopmentFactory> */
    use HasFactory;

    protected $table = 'pds_learning_developments';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'number_of_hours' => 'integer',
            'type' => LearningDevelopmentType::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
