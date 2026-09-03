<?php

namespace App\Models\Pds;

use App\Models\Employee;
use Database\Factories\Pds\DeclarationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'q34_related_third_degree', 'q34_related_fourth_degree', 'q34_related_details',
    'q35_administrative_offense', 'q35_administrative_details',
    'q35_criminally_charged', 'q35_criminal_details', 'q35_date_filed', 'q35_case_status',
    'q36_convicted', 'q36_details',
    'q37_separated_from_service', 'q37_details',
    'q38_candidate_in_election', 'q38_candidate_details',
    'q38_resigned_to_campaign', 'q38_resigned_details',
    'q39_immigrant_or_permanent_resident', 'q39_details',
    'q40_indigenous_group', 'q40_indigenous_details',
    'q40_person_with_disability', 'q40_pwd_id_no',
    'q40_solo_parent', 'q40_solo_parent_id_no',
    'government_id_type', 'government_id_number', 'government_id_issued',
    'date_accomplished',
])]
class Declaration extends Model
{
    /** @use HasFactory<DeclarationFactory> */
    use HasFactory;

    protected $table = 'pds_declarations';

    /**
     * Every question that carries a details field, paired with the field it
     * requires when the answer is yes. The form and the validation both read
     * from this, so they cannot drift apart.
     *
     * @var array<string, string>
     */
    public const DETAILS_REQUIRED_BY = [
        'q34_related_third_degree' => 'q34_related_details',
        'q34_related_fourth_degree' => 'q34_related_details',
        'q35_administrative_offense' => 'q35_administrative_details',
        'q35_criminally_charged' => 'q35_criminal_details',
        'q36_convicted' => 'q36_details',
        'q37_separated_from_service' => 'q37_details',
        'q38_candidate_in_election' => 'q38_candidate_details',
        'q38_resigned_to_campaign' => 'q38_resigned_details',
        'q39_immigrant_or_permanent_resident' => 'q39_details',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'q34_related_third_degree' => 'boolean',
            'q34_related_fourth_degree' => 'boolean',
            'q35_administrative_offense' => 'boolean',
            'q35_criminally_charged' => 'boolean',
            'q35_date_filed' => 'date',
            'q36_convicted' => 'boolean',
            'q37_separated_from_service' => 'boolean',
            'q38_candidate_in_election' => 'boolean',
            'q38_resigned_to_campaign' => 'boolean',
            'q39_immigrant_or_permanent_resident' => 'boolean',
            'q40_indigenous_group' => 'boolean',
            'q40_person_with_disability' => 'boolean',
            'q40_solo_parent' => 'boolean',
            'date_accomplished' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
