<?php

namespace App\Models\Pds;

use App\Models\Employee;
use Database\Factories\Pds\EligibilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'eligibility', 'rating', 'examination_date',
    'examination_place', 'license_number', 'license_validity', 'sort_order',
])]
class Eligibility extends Model
{
    /** @use HasFactory<EligibilityFactory> */
    use HasFactory;

    protected $table = 'pds_eligibilities';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'examination_date' => 'date',
            'license_validity' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
