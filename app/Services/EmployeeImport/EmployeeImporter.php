<?php

namespace App\Services\EmployeeImport;

use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only thing that writes employees. It accepts an ImportPreview and
 * nothing else, so nothing reaches the database without having been parsed
 * and shown first.
 */
class EmployeeImporter
{
    /**
     * @return int the number of employees created
     *
     * @throws InvalidArgumentException when any row in the preview has an error
     */
    public function import(ImportPreview $preview): int
    {
        if ($preview->hasErrors()) {
            throw new InvalidArgumentException(
                'This file still has '.count($preview->invalidRows()).' row(s) with errors.'
            );
        }

        $divisions = Division::pluck('id', 'code')->all();
        $sections = Section::pluck('id', 'code')->all();
        $positions = Position::pluck('id', 'title')->all();

        return DB::transaction(function () use ($preview, $divisions, $sections, $positions) {
            $created = 0;

            foreach ($preview->rows as $row) {
                $data = $row->data;

                Employee::create([
                    'employee_number' => $data['employee_number'],
                    'first_name' => $data['first_name'],
                    'middle_name' => $this->nullIfBlank($data['middle_name']),
                    'last_name' => $data['last_name'],
                    'suffix' => $this->nullIfBlank($data['suffix']),
                    'position_id' => $positions[$data['position_title']] ?? null,
                    'division_id' => $divisions[$data['division_code']] ?? null,
                    'section_id' => $sections[$data['section_code']] ?? null,
                    'employment_status' => $data['employment_status'] ?: 'permanent',
                    'date_hired' => $this->nullIfBlank($data['date_hired']),
                    'biometric_id' => $this->nullIfBlank($data['biometric_id']),
                    'is_active' => true,
                ]);

                $created++;
            }

            return $created;
        });
    }

    /**
     * A blank optional column must become null, never ''. biometric_id is
     * unique, and two empty strings collide where two nulls do not.
     */
    private function nullIfBlank(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
