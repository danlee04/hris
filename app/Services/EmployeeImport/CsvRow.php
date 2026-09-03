<?php

namespace App\Services\EmployeeImport;

/** One line of the uploaded file, and whatever is wrong with it. */
final readonly class CsvRow
{
    /**
     * @param  array<string, string>  $data
     * @param  list<string>  $errors
     */
    public function __construct(
        public int $lineNumber,
        public array $data,
        public array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
