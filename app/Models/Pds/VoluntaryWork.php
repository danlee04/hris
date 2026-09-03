<?php

namespace App\Models\Pds;

use App\Models\Employee;
use Database\Factories\Pds\VoluntaryWorkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'organization_name_address', 'date_from', 'date_to',
    'number_of_hours', 'position_nature_of_work', 'sort_order',
])]
class VoluntaryWork extends Model
{
    /** @use HasFactory<VoluntaryWorkFactory> */
    use HasFactory;

    protected $table = 'pds_voluntary_works';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'number_of_hours' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
