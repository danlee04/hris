<?php

namespace App\Console\Commands;

use App\Services\EmployeeImport\EmployeeCsvParser;
use App\Services\EmployeeImport\EmployeeImporter;
use Illuminate\Console\Command;

/**
 * The import screen, without the browser.
 *
 * It runs the same parser and the same importer, so it cannot drift from what
 * the screen does. It exists because a 500-row load is easier to iterate on
 * from a terminal, and because a bulk import should not depend on a browser
 * tab staying open.
 */
class ImportEmployees extends Command
{
    protected $signature = 'employees:import
                            {path : Path to the CSV file}
                            {--force : Write without asking for confirmation}';

    protected $description = 'Preview and import employees from a CSV file';

    public function handle(EmployeeCsvParser $parser, EmployeeImporter $importer): int
    {
        $path = $this->argument('path');

        if (! is_readable($path)) {
            $this->error("Cannot read [{$path}].");

            return self::FAILURE;
        }

        $preview = $parser->parse($path);

        $this->newLine();
        $this->line(sprintf(
            '%d ready, %d with errors.',
            count($preview->validRows()),
            count($preview->invalidRows())
        ));

        if ($preview->hasErrors()) {
            $this->newLine();

            $this->table(['Line', 'Employee No.', 'Problem'], collect($preview->invalidRows())
                ->flatMap(fn ($row) => array_map(
                    fn (string $error) => [$row->lineNumber, $row->data['employee_number'] ?? '', $error],
                    $row->errors
                ))
                ->all());

            $this->newLine();
            $this->error('Nothing was imported. Fix every row above and run this again.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            sprintf('Import %d employees?', count($preview->validRows())),
            true
        )) {
            $this->line('Cancelled. Nothing was written.');

            return self::SUCCESS;
        }

        $created = $importer->import($preview);

        $this->newLine();
        $this->info("{$created} employees imported.");

        return self::SUCCESS;
    }
}
