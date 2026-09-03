<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Position;
use App\Models\Section;
use Illuminate\Database\Seeder;

/**
 * Reference data. There is no screen for divisions, sections and positions in
 * Phase 1a — that is not in the definition of done and gets its own spec.
 *
 * The divisions and sections below are the hospital's real org chart, taken
 * from the legacy training system and corrected by hand. "Office of the Chief
 * Health Program Officer" heads two divisions, so its two codes carry a
 * division suffix to stay unique.
 *
 * The positions are examples only. The real plantilla is roughly 130 items and
 * still needs loading before the first employee import can resolve a position.
 */
class OrganizationSeeder extends Seeder
{
    /** @var array<string, string> code => name */
    private const DIVISIONS = [
        'FAD' => 'Finance and Administrative Division',
        'RITD' => 'Residential/Inpatient Treatment Division',
        'OAD' => 'Outpatient and Aftercare Division',
        'OCH' => 'Office of the Chief of Hospital',
    ];

    /** @var array<string, array{0: string, 1: string}> code => [division code, name] */
    private const SECTIONS = [
        // Finance and Administrative Division
        'OCAO' => ['FAD', 'Office of the Chief Administrative Officer'],
        'HRDS' => ['FAD', 'Human Resource Development Section'],
        'PROC' => ['FAD', 'Procurement Section'],
        'MMS' => ['FAD', 'Materials Management Section'],
        'GS' => ['FAD', 'General Services Section'],
        'BUDG' => ['FAD', 'Budget Section'],
        'ACCT' => ['FAD', 'Accounting Section'],
        'BCS' => ['FAD', 'Billing and Claims Section'],
        'COS' => ['FAD', 'Cash Operations Section'],

        // Residential/Inpatient Treatment Division
        'OCHPO-RITD' => ['RITD', 'Office of the Chief Health Program Officer'],
        'MED' => ['RITD', 'Medical Section'],
        'DETOX' => ['RITD', 'Detoxification / Mentally Ill Chemical Abusers (MICA) Section'],
        'PSY' => ['RITD', 'Psychology Section'],
        'MSW' => ['RITD', 'Medical Social Work Section'],
        'NURS' => ['RITD', 'Nursing Section'],
        'HIMS' => ['RITD', 'Health Information Management Section'],
        'LAB' => ['RITD', 'Clinical Laboratory Section'],
        'RAD' => ['RITD', 'Radiology Section'],
        'NUTR' => ['RITD', 'Nutritionist and Dietetics Section'],
        'DORM' => ['RITD', 'Dormitory Management Section'],
        'LIVE' => ['RITD', 'Livelihood Training Section'],
        'DENT' => ['RITD', 'Dental Section'],

        // Outpatient and Aftercare Division
        'OCHPO-OAD' => ['OAD', 'Office of the Chief Health Program Officer'],
        'OPD' => ['OAD', 'Outpatient Section'],
        'AFTR' => ['OAD', 'Aftercare Section'],

        // Office of the Chief of Hospital
        'OTRC' => ['OCH', 'Office of the TRC Chief'],
        'LEGAL' => ['OCH', 'Legal Unit'],
        'ICT' => ['OCH', 'Information and Communication Technology Unit'],
    ];

    /**
     * Examples, not the plantilla.
     *
     * @var array<int, array{0: string, 1: string, 2: int}> [title, item number, salary grade]
     */
    private const POSITIONS = [
        ['Statistician II', 'OSEC-DOHB-STAT2-97-2014', 15],
        ['Nurse I', 'OSEC-DOHB-NUR1-314-2014', 15],
    ];

    public function run(): void
    {
        foreach (self::DIVISIONS as $code => $name) {
            Division::firstOrCreate(['code' => $code], ['name' => $name]);
        }

        $divisionIds = Division::pluck('id', 'code');

        foreach (self::SECTIONS as $code => [$divisionCode, $name]) {
            Section::firstOrCreate(['code' => $code], [
                'division_id' => $divisionIds[$divisionCode],
                'name' => $name,
            ]);
        }

        foreach (self::POSITIONS as [$title, $itemNumber, $salaryGrade]) {
            Position::firstOrCreate(['item_number' => $itemNumber], [
                'title' => $title,
                'salary_grade' => $salaryGrade,
            ]);
        }
    }
}
