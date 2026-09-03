<?php

namespace Tests\Feature\EmployeeImport;

use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use App\Services\EmployeeImport\EmployeeCsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::factory()->create(['code' => 'ADMIN']);
        Section::factory()->create(['code' => 'STAT', 'division_id' => $division->id]);
        Position::factory()->create(['title' => 'Statistician II']);
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, implode(',', EmployeeCsvParser::COLUMNS)."\n".$body);

        return $path;
    }

    public function test_it_imports_a_clean_file(): void
    {
        $this->artisan('employees:import', [
            'path' => $this->csv(
                '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
            ),
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('employees', ['employee_number' => '2014-0042']);
    }

    public function test_it_refuses_a_file_with_errors_and_writes_nothing(): void
    {
        $this->artisan('employees:import', [
            'path' => $this->csv(
                "2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,\n".
                '2015-0100,Maria,Reyes,,,Statistician II,NOPE,STAT,permanent,2015-01-05,'
            ),
            '--force' => true,
        ])->assertFailed();

        $this->assertSame(0, Employee::count());
    }

    public function test_it_refuses_a_path_it_cannot_read(): void
    {
        $this->artisan('employees:import', ['path' => 'no/such/file.csv'])
            ->assertFailed();
    }

    public function test_declining_the_confirmation_writes_nothing(): void
    {
        $this->artisan('employees:import', [
            'path' => $this->csv(
                '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
            ),
        ])
            ->expectsConfirmation('Import 1 employees?', 'no')
            ->assertSuccessful();

        $this->assertSame(0, Employee::count());
    }
}
