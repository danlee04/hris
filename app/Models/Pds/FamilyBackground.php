<?php

namespace App\Models\Pds;

use App\Models\Employee;
use Database\Factories\Pds\FamilyBackgroundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'spouse_surname', 'spouse_first_name', 'spouse_middle_name', 'spouse_name_extension',
    'spouse_occupation', 'spouse_employer', 'spouse_business_address', 'spouse_telephone_no',
    'father_surname', 'father_first_name', 'father_middle_name', 'father_name_extension',
    'mother_surname', 'mother_first_name', 'mother_middle_name',
])]
class FamilyBackground extends Model
{
    /** @use HasFactory<FamilyBackgroundFactory> */
    use HasFactory;

    protected $table = 'pds_family_background';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
