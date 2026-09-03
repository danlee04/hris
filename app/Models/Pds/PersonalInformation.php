<?php

namespace App\Models\Pds;

use App\Enums\CivilStatus;
use App\Enums\Sex;
use App\Models\Employee;
use Database\Factories\Pds\PersonalInformationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'date_of_birth', 'place_of_birth', 'sex', 'civil_status', 'civil_status_other',
    'height_m', 'weight_kg', 'blood_type',
    'gsis_id', 'pagibig_id', 'philhealth_no', 'sss_no', 'tin_no',
    'agency_employee_no', 'philsys_id',
    'citizenship', 'dual_citizenship_by', 'dual_citizenship_country',
    'res_house_no', 'res_street', 'res_subdivision', 'res_barangay',
    'res_city', 'res_province', 'res_zip_code',
    'permanent_same_as_residential',
    'perm_house_no', 'perm_street', 'perm_subdivision', 'perm_barangay',
    'perm_city', 'perm_province', 'perm_zip_code',
    'telephone_no', 'mobile_no', 'email_address', 'photo_path',
])]
class PersonalInformation extends Model
{
    /** @use HasFactory<PersonalInformationFactory> */
    use HasFactory;

    protected $table = 'pds_personal_information';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'sex' => Sex::class,
            'civil_status' => CivilStatus::class,
            'height_m' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'permanent_same_as_residential' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
