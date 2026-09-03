<?php

namespace App\Services\EmployeeImport;

/** Everything the parser read, with nothing written. */
final readonly class ImportPreview
{
    /** @param  list<CsvRow>  $rows */
    public function __construct(public array $rows) {}

    /** @return list<CsvRow> */
    public function validRows(): array
    {
        return array_values(array_filter($this->rows, fn (CsvRow $row) => $row->isValid()));
    }

    /** @return list<CsvRow> */
    public function invalidRows(): array
    {
        return array_values(array_filter($this->rows, fn (CsvRow $row) => ! $row->isValid()));
    }

    public function hasErrors(): bool
    {
        return $this->invalidRows() !== [];
    }
}
