<?php

namespace App\Services\EmployeeImport;

use App\Enums\EmploymentStatus;
use SplFileObject;

/**
 * Reads an uploaded CSV and says what is wrong with it. It writes nothing —
 * that is EmployeeImporter's job, and the importer accepts only what came from
 * here. Preview-before-write is enforced by the shape of the code rather than
 * by remembering to click the right button.
 */
class EmployeeCsvParser
{
    /** @var list<string> */
    public const COLUMNS = [
        'employee_number',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'position_title',
        'division_code',
        'section_code',
        'employment_status',
        'date_hired',
        'biometric_id',
    ];

    /** @var list<string> */
    private const REQUIRED = [
        'employee_number',
        'first_name',
        'last_name',
    ];

    public function parse(string $path): ImportPreview
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        // An empty escape turns off PHP's proprietary backslash escaping, which
        // no spreadsheet produces and which RFC 4180 does not describe. PHP 8.4
        // deprecates leaving this unset; PHP 9 will require it.
        $file->setCsvControl(',', '"', '');

        $header = $file->fgetcsv();

        if ($header !== self::COLUMNS) {
            return new ImportPreview([new CsvRow(1, [], [
                'The header does not match the expected columns: '.implode(', ', self::COLUMNS),
            ])]);
        }

        $reference = ReferenceData::load();

        $rows = [];
        $seen = [];
        $lineNumber = 1;

        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $lineNumber++;

            if ($values === false || $values === [null]) {
                continue;
            }

            $data = array_combine(
                self::COLUMNS,
                array_map(
                    fn ($value) => trim((string) $value),
                    // Excel drops trailing empty columns on export, so a short
                    // row is normal and gets padded rather than rejected.
                    array_pad(array_slice($values, 0, count(self::COLUMNS)), count(self::COLUMNS), '')
                )
            );

            $errors = $this->errorsFor($data, $reference, $seen);

            if ($data['employee_number'] !== '' && ! isset($seen[$data['employee_number']])) {
                $seen[$data['employee_number']] = $lineNumber;
            }

            $rows[] = new CsvRow($lineNumber, $data, $errors);
        }

        return new ImportPreview($rows);
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, int>  $seen
     * @return list<string>
     */
    private function errorsFor(array $data, ReferenceData $reference, array $seen): array
    {
        $errors = [];

        foreach (self::REQUIRED as $column) {
            if ($data[$column] === '') {
                $errors[] = "{$column} is required";
            }
        }

        $number = $data['employee_number'];

        if ($number !== '' && $reference->numberIsTaken($number)) {
            $errors[] = "employee_number [{$number}] already exists";
        }

        if ($number !== '' && isset($seen[$number])) {
            $errors[] = "employee_number [{$number}] is repeated on line {$seen[$number]}";
        }

        if ($data['division_code'] !== '' && $reference->divisionId($data['division_code']) === null) {
            $errors[] = "division_code [{$data['division_code']}] does not exist";
        }

        if ($data['section_code'] !== '' && $reference->sectionId($data['section_code']) === null) {
            $errors[] = "section_code [{$data['section_code']}] does not exist";
        }

        if ($data['position_title'] !== '' && $reference->positionId($data['position_title']) === null) {
            $errors[] = "position_title [{$data['position_title']}] does not exist";
        }

        if ($data['employment_status'] !== ''
            && EmploymentStatus::fromLoose($data['employment_status']) === null) {
            $errors[] = "employment_status [{$data['employment_status']}] is not one of: "
                .implode(', ', EmploymentStatus::labels());
        }

        if ($data['date_hired'] !== '' && strtotime($data['date_hired']) === false) {
            $errors[] = "date_hired [{$data['date_hired']}] is not a date";
        }

        return $errors;
    }
}
