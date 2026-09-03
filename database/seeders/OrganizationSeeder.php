<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Position;
use App\Models\Section;
use Illuminate\Database\Seeder;

/**
 * Reference data. There is no screen for divisions, sections and positions in
 * Phase 1a — that is not in the definition of done and gets its own spec. The
 * rows below are examples; replace them with the hospital's real org chart and
 * plantilla before the first employee import.
 */
class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $divisions = [
            'ADMIN' => 'Administrative Division',
            'MED' => 'Medical Division',
        ];

        foreach ($divisions as $code => $name) {
            Division::firstOrCreate(['code' => $code], ['name' => $name]);
        }

        $sections = [
            'STAT' => ['ADMIN', 'Statistics Unit'],
            'NURS' => ['MED', 'Nursing Service'],
        ];

        foreach ($sections as $code => [$divisionCode, $name]) {
            Section::firstOrCreate(['code' => $code], [
                'division_id' => Division::where('code', $divisionCode)->value('id'),
                'name' => $name,
            ]);
        }

        $positions = [
            ['Statistician II', 'OSEC-DOHB-STAT2-97-2014', 15],
            ['Nurse I', 'OSEC-DOHB-NUR1-314-2014', 15],
        ];

        foreach ($positions as [$title, $itemNumber, $salaryGrade]) {
            Position::firstOrCreate(['item_number' => $itemNumber], [
                'title' => $title,
                'salary_grade' => $salaryGrade,
            ]);
        }
    }
}
