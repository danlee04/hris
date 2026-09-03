<?php

namespace App\Services\Pds;

use App\Enums\CivilStatus;
use App\Models\Employee;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
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
    public function __construct(private readonly TemplateMap $map) {}

    /**
     * @return string the path of the filled workbook
     */
    public function export(Employee $employee): string
    {
        $employee->loadMissing(['personalInformation', 'familyBackground']);

        $book = IOFactory::load($this->map->path());

        $this->writePersonalInformation($book, $employee);
        $this->writeFamilyBackground($book, $employee);
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
        $this->put($sheet, $cells['surname'], $employee->last_name);
        $this->put($sheet, $cells['first_name'], $employee->first_name);
        $this->put($sheet, $cells['middle_name'], $employee->middle_name);
        $this->put($sheet, $cells['name_extension'], $employee->suffix);

        if ($record === null) {
            return;
        }

        foreach ($cells as $field => $reference) {
            if (in_array($field, ['surname', 'first_name', 'middle_name', 'name_extension'], true)) {
                continue;
            }

            $this->put($sheet, $reference, $record->{$field} ?? null);
        }

        $this->tick($sheet, 'sex', $record->sex?->value);
        $this->tick($sheet, 'civil_status', $record->civil_status?->value);

        // A solo parent has no box of their own on this form. They tick
        // Other/s, and the word goes in the text cell beside it.
        if ($record->civil_status === CivilStatus::SoloParent) {
            $this->put($sheet, $cells['civil_status_other'], $record->civil_status->label());
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

    /**
     * Ticks one box of a group.
     *
     * The boxes are Excel form controls, each linked to a cell holding TRUE or
     * FALSE. Writing the option's text into that cell would replace the
     * control's value and leave the box empty on the printed page.
     */
    private function tick(Worksheet $sheet, string $group, ?string $option): void
    {
        if ($option === null) {
            return;
        }

        $cell = config("pds_template.ticks.{$group}.{$option}");

        if ($cell === null) {
            return;
        }

        $sheet->setCellValueExplicit($cell, true, DataType::TYPE_BOOL);
    }

    private function writeFamilyBackground(Spreadsheet $book, Employee $employee): void
    {
        $record = $employee->familyBackground;

        if ($record === null) {
            return;
        }

        $sheet = $this->sheet($book, 'family_background');

        foreach ($this->map->cells('family_background') as $field => $reference) {
            $this->put($sheet, $reference, $record->{$field} ?? null);
        }
    }

    /**
     * The 2026 revision prints a date beside the signature on every page.
     * The signature box itself is left alone until the HR office says what
     * belongs in it.
     */
    private function writePageDates(Spreadsheet $book): void
    {
        foreach (config('pds_template.page_dates') as $page) {
            $sheet = $book->getSheetByName($this->map->sheet($page['sheet']));

            $this->put($sheet, $page['cell'], now());
        }
    }

    private function sheet(Spreadsheet $book, string $section): Worksheet
    {
        return $book->getSheetByName(
            $this->map->sheet($this->map->section($section)['sheet'])
        );
    }

    /**
     * Everything reaches the sheet as text.
     *
     * A date written as a spreadsheet serial renders in whatever the reader's
     * locale decides, and this form asks for dd/mm/yyyy on every date field.
     * An enum written raw would print `solo_parent` where the form expects
     * "Solo Parent".
     */
    private function put(Worksheet $sheet, string $reference, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $sheet->setCellValueExplicit(
            $reference,
            $this->asText($value),
            DataType::TYPE_STRING,
        );
    }

    private function asText(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($this->map->dateFormat());
        }

        if ($value instanceof BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : (string) $value->value;
        }

        if (is_bool($value)) {
            return $value ? 'Y' : 'N';
        }

        if ($value instanceof Model) {
            return (string) $value->getKey();
        }

        return (string) $value;
    }
}
