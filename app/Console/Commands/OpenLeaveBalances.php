<?php

namespace App\Console\Commands;

use App\Enums\LeaveLedgerKind;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Services\Leave\LeaveLedger;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carries HR's leave card into the ledger.
 *
 * Every balance reads zero until this has been run, and an application filed
 * against a zero balance is leave without pay. This is the one thing standing
 * between a working leave module and a usable one.
 *
 * It is all-or-nothing on purpose. A half-applied file cannot be told apart
 * from a whole one afterwards, and the ledger refuses a second opening balance,
 * so a re-run to finish the job would fail on every row that did land.
 */
class OpenLeaveBalances extends Command
{
    protected $signature = 'leave:open-balances
                            {path : Path to the CSV file}
                            {--as-of= : The date the figures were taken (Y-m-d), today by default}
                            {--force : Write without asking for confirmation}';

    protected $description = "Record opening leave balances from HR's spreadsheet";

    public function handle(LeaveLedger $ledger): int
    {
        $path = $this->argument('path');

        if (! is_readable($path)) {
            $this->error("Cannot read [{$path}].");

            return self::FAILURE;
        }

        try {
            $asOf = $this->option('as-of')
                ? Carbon::createFromFormat('Y-m-d', $this->option('as-of'))->startOfDay()
                : Carbon::now();
        } catch (InvalidFormatException) {
            $this->error('--as-of has to be a date in the form 2025-12-31.');

            return self::FAILURE;
        }

        $rows = $this->read($path);

        if ($rows === []) {
            $this->error('The file has no rows.');

            return self::FAILURE;
        }

        $header = array_shift($rows);

        $problem = $this->headerProblem($header);

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        [$entries, $errors] = $this->check($header, $rows);

        if ($errors !== []) {
            $this->newLine();
            $this->table(['Line', 'Employee No.', 'Problem'], $errors);
            $this->newLine();
            $this->error('Nothing was written. Fix every row above and run this again.');

            return self::FAILURE;
        }

        $employees = count(array_unique(array_column($entries, 'employee_id')));

        if (! $this->option('force') && ! $this->confirm(sprintf(
            'Open %d %s for %d %s?',
            count($entries), Str::plural('balance', count($entries)),
            $employees, Str::plural('employee', $employees),
        ), true)) {
            $this->line('Cancelled. Nothing was written.');

            return self::SUCCESS;
        }

        // Validation above rules out every refusal the ledger knows how to
        // make. The transaction covers what it cannot see: a connection that
        // drops on row 90 of 134.
        DB::transaction(function () use ($entries, $ledger, $asOf) {
            foreach ($entries as $entry) {
                $ledger->open($entry['employee'], $entry['ledger'], $entry['days'], $asOf);
            }
        });

        $this->newLine();
        $this->info(sprintf('%d opening balances recorded for %d employees, as of %s.',
            count($entries), $employees, $asOf->format('d/m/Y')));

        return self::SUCCESS;
    }

    /**
     * The header is the mapping, so it is checked before anything else.
     *
     * A column that is not a ledger is refused rather than ignored: a
     * misspelled "vacaton" that imported nothing would finish with a success
     * message and an empty ledger, which is the one outcome nobody would think
     * to check.
     *
     * @param  list<string>  $header
     */
    private function headerProblem(array $header): ?string
    {
        if (($header[0] ?? null) !== 'employee_number') {
            return 'The first column has to be employee_number.';
        }

        $ledgers = $this->ledgers();
        $columns = array_slice($header, 1);

        if ($columns === []) {
            return 'The file has no ledger columns. Add one of: '.implode(', ', $ledgers).'.';
        }

        foreach ($columns as $column) {
            if (! in_array($column, $ledgers, true)) {
                return "[{$column}] is not a leave ledger. The ledgers are: ".implode(', ', $ledgers).'.';
            }
        }

        return null;
    }

    /**
     * Every row against every rule, before a single write.
     *
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<array<int, string|int>>}
     */
    private function check(array $header, array $rows): array
    {
        $columns = array_slice($header, 1);

        // One query each rather than one per row: this file is 134 rows long
        // and there is no reason for it to be 268 queries.
        $employees = Employee::whereIn('employee_number', array_filter(array_column($rows, 0)))
            ->get()->keyBy('employee_number');

        $alreadyOpen = LeaveLedgerEntry::where('kind', LeaveLedgerKind::Opening->value)
            ->get(['employee_id', 'ledger'])
            ->map(fn ($entry) => "{$entry->employee_id}:{$entry->ledger}")
            ->all();

        $entries = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            // Line 1 is the header, and the person fixing this is looking at a
            // spreadsheet whose rows are numbered from 1.
            $line = $index + 2;
            $number = trim((string) ($row[0] ?? ''));

            if ($number === '') {
                $errors[] = [$line, '', 'No employee number.'];

                continue;
            }

            $employee = $employees->get($number);

            if (! $employee) {
                $errors[] = [$line, $number, 'No employee has this number.'];

                continue;
            }

            foreach ($columns as $position => $ledger) {
                $days = trim((string) ($row[$position + 1] ?? ''));

                // A blank is not a zero. Zero says the person has no credits;
                // blank says the spreadsheet did not answer, and an opening
                // balance invented from silence is a figure nobody put there.
                if ($days === '') {
                    continue;
                }

                if (! is_numeric($days) || (float) $days < 0) {
                    $errors[] = [$line, $number, "{$ledger}: [{$days}] is not a number of days."];

                    continue;
                }

                if (in_array("{$employee->id}:{$ledger}", $alreadyOpen, true)) {
                    $errors[] = [$line, $number, "{$ledger}: already has an opening balance. Correct it with an adjustment, which can say why."];

                    continue;
                }

                $entries[] = [
                    'employee' => $employee,
                    'employee_id' => $employee->id,
                    'ledger' => $ledger,
                    'days' => (float) $days,
                ];
            }
        }

        return [$entries, $errors];
    }

    /**
     * The ledgers that exist, asked of the leave types rather than listed here.
     * A type added on the Leave types screen brings its ledger with it.
     *
     * @return list<string>
     */
    private function ledgers(): array
    {
        return LeaveType::whereNotNull('ledger')
            ->distinct()
            ->orderBy('ledger')
            ->pluck('ledger')
            ->all();
    }

    /** @return list<list<string>> */
    private function read(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            // A row of nothing is what a trailing newline looks like.
            if ($row === [null] || $row === ['']) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        // Excel writes a byte order mark, and then nothing matches
        // 'employee_number' for a reason the header does not show.
        if (isset($rows[0][0])) {
            $rows[0][0] = ltrim($rows[0][0], "\u{FEFF}");
        }

        return $rows;
    }
}
