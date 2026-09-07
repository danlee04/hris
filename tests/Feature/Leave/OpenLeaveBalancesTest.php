<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveLedgerKind;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Services\Leave\LeaveLedger;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenLeaveBalancesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'balances').'.csv';
        file_put_contents($path, $contents);

        $this->files[] = $path;

        return $path;
    }

    public function test_it_opens_a_balance_for_every_ledger_column(): void
    {
        $employee = Employee::factory()->create(['employee_number' => '2019-0042']);

        $path = $this->csv(<<<'CSV'
        employee_number,vacation,sick
        2019-0042,15.50,22.00
        CSV);

        $this->artisan('leave:open-balances', ['path' => $path, '--force' => true])
            ->assertSuccessful();

        $ledger = app(LeaveLedger::class);

        $this->assertSame(15.5, $ledger->balance($employee, 'vacation'));
        $this->assertSame(22.0, $ledger->balance($employee, 'sick'));
    }

    public function test_a_blank_cell_records_nothing_at_all(): void
    {
        // A zero says the person has no credits. A blank says the spreadsheet
        // did not answer, and an entry invented from silence is a figure
        // nobody put there.
        $employee = Employee::factory()->create(['employee_number' => '2019-0042']);

        $path = $this->csv(<<<'CSV'
        employee_number,vacation,sick
        2019-0042,,0
        CSV);

        $this->artisan('leave:open-balances', ['path' => $path, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('leave_ledger_entries', [
            'employee_id' => $employee->id,
            'ledger' => 'vacation',
        ]);

        $this->assertDatabaseHas('leave_ledger_entries', [
            'employee_id' => $employee->id,
            'ledger' => 'sick',
            'kind' => LeaveLedgerKind::Opening->value,
        ]);
    }

    public function test_it_records_the_date_the_figures_were_taken_from(): void
    {
        // HR's leave card says "as of 31 December 2025". The ledger has to say
        // the same, or the balance is dated the day somebody got around to
        // typing it in.
        $employee = Employee::factory()->create(['employee_number' => '2019-0042']);

        $path = $this->csv(<<<'CSV'
        employee_number,vacation
        2019-0042,10
        CSV);

        $this->artisan('leave:open-balances', [
            'path' => $path,
            '--as-of' => '2025-12-31',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('2025-12-31', LeaveLedgerEntry::where('employee_id', $employee->id)
            ->sole()->effective_date->format('Y-m-d'));
    }

    public function test_it_refuses_a_column_that_is_not_a_ledger(): void
    {
        // A misspelled header that quietly imported nothing would look exactly
        // like a successful run.
        Employee::factory()->create(['employee_number' => '2019-0042']);

        $path = $this->csv(<<<'CSV'
        employee_number,vacaton
        2019-0042,10
        CSV);

        $this->artisan('leave:open-balances', ['path' => $path, '--force' => true])
            ->assertFailed();

        $this->assertDatabaseCount('leave_ledger_entries', 0);
    }

    public function test_it_refuses_a_file_without_an_employee_number_column(): void
    {
        $path = $this->csv(<<<'CSV'
        name,vacation
        Dela Cruz,10
        CSV);

        $this->artisan('leave:open-balances', ['path' => $path, '--force' => true])
            ->assertFailed();
    }

    public function test_one_bad_row_stops_the_whole_file(): void
    {
        // Half an import is worse than none: nobody can tell which half.
        Employee::factory()->create(['employee_number' => '2019-0042']);

        $path = $this->csv(<<<'CSV'
        employee_number,vacation
        2019-0042,10
        1998-9999,12
        CSV);

        $this->artisan('leave:open-balances', ['path' => $path, '--force' => true])
            ->assertFailed();

        $this->assertDatabaseCount('leave_ledger_entries', 0);
    }

    public function test_it_refuses_days_that_are_not_a_positive_number(): void
    {
        Employee::factory()->create(['employee_number' => '2019-0042']);

        foreach (['-1', 'n/a'] as $days) {
            $path = $this->csv("employee_number,vacation\n2019-0042,{$days}\n");

            $this->artisan('leave:open-balances', ['path' => $path, '--force' => true])
                ->assertFailed();
        }

        $this->assertDatabaseCount('leave_ledger_entries', 0);
    }

    public function test_it_refuses_a_ledger_that_is_already_open(): void
    {
        // The second figure is a correction, and a correction has to say what
        // it is correcting. A spreadsheet column cannot say that.
        $employee = Employee::factory()->create(['employee_number' => '2019-0042']);
        app(LeaveLedger::class)->open($employee, 'vacation', 5);

        $path = $this->csv(<<<'CSV'
        employee_number,vacation
        2019-0042,10
        CSV);

        $this->artisan('leave:open-balances', ['path' => $path, '--force' => true])
            ->assertFailed();

        $this->assertSame(5.0, app(LeaveLedger::class)->balance($employee, 'vacation'));
    }

    public function test_it_refuses_a_file_it_cannot_read(): void
    {
        $this->artisan('leave:open-balances', ['path' => 'nowhere.csv'])
            ->assertFailed();
    }

    public function test_it_asks_before_writing(): void
    {
        $employee = Employee::factory()->create(['employee_number' => '2019-0042']);

        $path = $this->csv(<<<'CSV'
        employee_number,vacation
        2019-0042,10
        CSV);

        $this->artisan('leave:open-balances', ['path' => $path])
            ->expectsConfirmation('Open 1 balance for 1 employee?', 'no')
            ->assertSuccessful();

        $this->assertDatabaseCount('leave_ledger_entries', 0);
    }
}
