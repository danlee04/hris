<?php

namespace App\Services\Pds;

use App\Enums\CivilStatus;
use App\Enums\OtherEntryKind;
use App\Models\Employee;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Fills the official CS Form 212 template with one employee's PDS.
 *
 * The template is loaded, written to in memory, and saved somewhere else. It
 * is never saved back over itself — that file cannot be regenerated from
 * anything in this repository, and a single wrong path would take it.
 */
class PdsExporter
{
    public function __construct(
        private readonly TemplateMap $map,
        private readonly CellWriter $writer,
        private readonly SectionWriter $sections,
    ) {}

    /**
     * @return string the path of the filled workbook
     */
    public function export(Employee $employee): string
    {
        $employee->loadMissing([
            'personalInformation', 'familyBackground', 'children', 'educations',
            'eligibilities', 'workExperiences', 'voluntaryWorks',
            'learningDevelopments', 'otherEntries', 'declaration', 'references',
        ]);

        $book = IOFactory::load($this->map->path());

        $this->writePersonalInformation($book, $employee);
        $this->writeFamilyBackground($book, $employee);
        $this->writeChildren($book, $employee);
        $this->writeEducation($book, $employee);
        $this->writeRepeatingSections($book, $employee);
        $this->writeOtherInformation($book, $employee);
        $this->writeDeclarations($book, $employee);
        $this->writeReferences($book, $employee);
        $this->writePageDates($book);

        $path = tempnam(sys_get_temp_dir(), 'pds').'.xlsx';

        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $book->disconnectWorksheets();

        return $path;
    }

    public function filename(Employee $employee): string
    {
        $name = Str::of($employee->last_name.' '.$employee->first_name)
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '_')
            ->trim('_');

        return "PDS_{$name}_".now()->format('Y-m-d').'.xlsx';
    }

    private function writePersonalInformation(Spreadsheet $book, Employee $employee): void
    {
        $sheet = $this->sheet($book, 'personal_information');
        $cells = $this->map->cells('personal_information');
        $record = $employee->personalInformation;

        // Items 1 and 2 come from the employee master. The PDS tables do not
        // hold a second copy of the name, and a second copy would drift.
        $this->writer->put($sheet, $cells['surname'], $employee->last_name);
        $this->writer->put($sheet, $cells['first_name'], $employee->first_name);
        $this->writer->put($sheet, $cells['middle_name'], $employee->middle_name);
        $this->writer->put($sheet, $cells['name_extension'], $employee->suffix);

        if ($record === null) {
            return;
        }

        foreach ($cells as $field => $reference) {
            if (in_array($field, ['surname', 'first_name', 'middle_name', 'name_extension'], true)) {
                continue;
            }

            $this->writer->put($sheet, $reference, $record->{$field} ?? null);
        }

        $this->tick($sheet, 'sex', $record->sex?->value);
        $this->tick($sheet, 'civil_status', $record->civil_status?->value);

        // A solo parent has no box of their own on this form. They tick
        // Other/s, and the word goes in the text cell beside it.
        if ($record->civil_status === CivilStatus::SoloParent) {
            $this->writer->put($sheet, $cells['civil_status_other'], $record->civil_status->label());
        }

        if ($record->citizenship !== null) {
            $this->tick(
                $sheet,
                'citizenship',
                Str::contains($record->citizenship, 'dual', ignoreCase: true) ? 'dual' : 'filipino',
            );
        }

        $this->tick($sheet, 'dual_citizenship_by', match (Str::lower((string) $record->dual_citizenship_by)) {
            'by birth', 'birth' => 'by_birth',
            'by naturalization', 'naturalization' => 'by_naturalization',
            default => null,
        });
    }

    private function writeFamilyBackground(Spreadsheet $book, Employee $employee): void
    {
        $record = $employee->familyBackground;

        if ($record === null) {
            return;
        }

        $sheet = $this->sheet($book, 'family_background');

        foreach ($this->map->cells('family_background') as $field => $reference) {
            $this->writer->put($sheet, $reference, $record->{$field} ?? null);
        }
    }

    private function writeChildren(Spreadsheet $book, Employee $employee): void
    {
        $this->sections->write($book, 'children', $employee->children
            ->map(fn ($child) => [
                'name' => $child->name,
                'date_of_birth' => $child->date_of_birth,
            ])
            ->all());
    }

    /**
     * The printed form gives one row per level and no more. A second degree at
     * the same level is not dropped — it goes to the C8 continuation sheet,
     * which carries a level column of its own for exactly this.
     */
    private function writeEducation(Spreadsheet $book, Employee $employee): void
    {
        $section = $this->map->section('education');
        $sheet = $book->getSheetByName($this->map->sheet($section['sheet']));

        $seen = [];
        $overflow = [];

        foreach ($employee->educations as $education) {
            $level = $education->level?->value;
            $row = $section['rows_by_level'][$level] ?? null;

            if ($row === null || isset($seen[$level])) {
                $overflow[] = $this->educationRow($education, withLevel: true);

                continue;
            }

            $seen[$level] = true;

            foreach ($section['columns'] as $field => $column) {
                $this->writer->put($sheet, $column.$row, $education->{$field});
            }
        }

        if ($overflow === []) {
            return;
        }

        $continuation = $section['continuation'];
        $sheet = $book->getSheetByName($this->map->sheet($continuation['sheet']));

        foreach (array_slice($overflow, 0, $continuation['row_count']) as $offset => $row) {
            foreach ($continuation['columns'] as $field => $column) {
                $this->writer->put($sheet, $column.($continuation['first_row'] + $offset), $row[$field] ?? null);
            }
        }
    }

    /** @return array<string, mixed> */
    private function educationRow(object $education, bool $withLevel): array
    {
        $row = [
            'school_name' => $education->school_name,
            'degree_course' => $education->degree_course,
            'period_from' => $education->period_from,
            'period_to' => $education->period_to,
            'highest_level_units' => $education->highest_level_units,
            'year_graduated' => $education->year_graduated,
            'honors' => $education->honors,
        ];

        return $withLevel ? ['level' => $education->level] + $row : $row;
    }

    private function writeRepeatingSections(Spreadsheet $book, Employee $employee): void
    {
        $sections = [
            'eligibility' => [$employee->eligibilities, [
                'eligibility', 'rating', 'examination_date', 'examination_place',
                'license_number', 'license_validity',
            ]],
            'work_experience' => [$employee->workExperiences, [
                'date_from', 'date_to', 'position_title', 'department_agency',
                'monthly_salary', 'salary_grade_step', 'status_of_appointment',
                'is_government_service',
            ]],
            'learning_development' => [$employee->learningDevelopments, [
                'title', 'date_from', 'date_to', 'number_of_hours', 'type', 'conducted_by',
            ]],
            'voluntary_work' => [$employee->voluntaryWorks, [
                'organization_name_address', 'date_from', 'date_to',
                'number_of_hours', 'position_nature_of_work',
            ]],
        ];

        foreach ($sections as $key => [$records, $fields]) {
            $this->sections->write($book, $key, $records
                ->map(fn ($record) => collect($fields)
                    ->mapWithKeys(fn (string $field) => [$field => $record->{$field}])
                    ->all())
                ->all());
        }
    }

    /**
     * Items 31 to 33 print side by side in the same rows, each its own list.
     * They overflow independently, so each column is written on its own.
     */
    private function writeOtherInformation(Spreadsheet $book, Employee $employee): void
    {
        $section = $this->map->section('other_information');
        $continuation = $section['continuation'];

        $sheet = $book->getSheetByName($this->map->sheet($section['sheet']));
        $contSheet = $book->getSheetByName($this->map->sheet($continuation['sheet']));

        foreach (OtherEntryKind::cases() as $kind) {
            $values = $employee->otherEntries
                ->where('kind', $kind)
                ->pluck('value')
                ->values()
                ->all();

            $column = $section['columns'][$kind->value];

            foreach (array_slice($values, 0, $section['row_count']) as $offset => $value) {
                $this->writer->put($sheet, $column.($section['first_row'] + $offset), $value);
            }

            $overflow = array_slice($values, $section['row_count'], $continuation['row_count']);
            $contColumn = $continuation['columns'][$kind->value];

            foreach ($overflow as $offset => $value) {
                $this->writer->put($contSheet, $contColumn.($continuation['first_row'] + $offset), $value);
            }
        }
    }

    /**
     * Items 34 to 40 and 42.
     *
     * An unanswered question ticks neither box. Unanswered and "no" are
     * different things on a form signed under penalty of perjury, and turning
     * the first into the second would put words in the employee's mouth.
     */
    private function writeDeclarations(Spreadsheet $book, Employee $employee): void
    {
        $record = $employee->declaration;

        if ($record === null) {
            return;
        }

        $section = $this->map->section('declarations');
        $sheet = $book->getSheetByName($this->map->sheet($section['sheet']));

        foreach ($section['questions'] as $field => $boxes) {
            $answer = $record->{$field};

            if ($answer === null) {
                continue;
            }

            $this->writer->tick($sheet, $answer ? $boxes['yes'] : $boxes['no']);
        }

        foreach ($section['cells'] as $field => $reference) {
            $this->writer->put($sheet, $reference, $record->{$field} ?? null);
        }
    }

    private function writeReferences(Spreadsheet $book, Employee $employee): void
    {
        $this->sections->write($book, 'references', $employee->references
            ->map(fn ($reference) => [
                'name' => $reference->name,
                'address' => $reference->address,
                'contact_details' => $reference->contact_details,
            ])
            ->all());
    }

    /**
     * The 2026 revision prints a date beside the signature on every page. The
     * signature box itself is left alone until the HR office says what belongs
     * in it.
     */
    private function writePageDates(Spreadsheet $book): void
    {
        foreach (config('pds_template.page_dates') as $page) {
            $sheet = $book->getSheetByName($this->map->sheet($page['sheet']));

            $this->writer->put($sheet, $page['cell'], now());
        }
    }

    /** Ticks one box of a group; a null option ticks nothing. */
    private function tick(Worksheet $sheet, string $group, ?string $option): void
    {
        $cell = $option === null ? null : config("pds_template.ticks.{$group}.{$option}");

        if ($cell !== null) {
            $this->writer->tick($sheet, $cell);
        }
    }

    private function sheet(Spreadsheet $book, string $section): Worksheet
    {
        return $book->getSheetByName(
            $this->map->sheet($this->map->section($section)['sheet'])
        );
    }
}
