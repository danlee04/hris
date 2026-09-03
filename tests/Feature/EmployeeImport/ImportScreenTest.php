<?php

namespace Tests\Feature\EmployeeImport;

use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use App\Models\User;
use App\Services\EmployeeImport\EmployeeCsvParser;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ImportScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $division = Division::factory()->create(['code' => 'ADMIN']);
        Section::factory()->create(['code' => 'STAT', 'division_id' => $division->id]);
        Position::factory()->create(['title' => 'Statistician II']);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function upload(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'employees.csv',
            implode(',', EmployeeCsvParser::COLUMNS)."\n".$body
        );
    }

    public function test_an_employee_cannot_open_the_import_screen(): void
    {
        $this->actingAs($this->userWithRole('employee'))
            ->get(route('employees.import'))
            ->assertForbidden();
    }

    public function test_hr_can_open_the_import_screen(): void
    {
        $this->actingAs($this->userWithRole('hr'))
            ->get(route('employees.import'))
            ->assertOk();
    }

    public function test_uploading_shows_a_preview_and_writes_nothing(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.import')
            ->set('file', $this->upload(
                '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
            ))
            ->assertSet('previewRows.0.line', 2)
            ->assertSet('previewRows.0.name', 'Dela Cruz, Juan')
            ->assertSet('previewRows.0.errors', [])
            ->assertSet('validCount', 1)
            ->assertSet('errorCount', 0);

        $this->assertSame(0, Employee::count());
    }

    public function test_the_preview_names_the_problem_on_the_line_it_is_on(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.import')
            ->set('file', $this->upload(
                "2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,\n".
                '2015-0100,Maria,Reyes,,,Statistician II,NOPE,STAT,permanent,2015-01-05,'
            ))
            ->assertSet('validCount', 1)
            ->assertSet('errorCount', 1)
            ->assertSet('previewRows.1.line', 3)
            ->assertSet('previewRows.1.errors', [
                'last_name is required',
                'division_code [NOPE] does not exist',
            ]);
    }

    public function test_committing_a_clean_preview_writes_the_employees(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.import')
            ->set('file', $this->upload(
                '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
            ))
            ->call('commit')
            ->assertHasNoErrors()
            ->assertSet('imported', 1);

        $this->assertSame(1, Employee::count());
        $this->assertDatabaseHas('employees', ['employee_number' => '2014-0042']);
    }

    public function test_a_preview_with_errors_cannot_be_committed(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.import')
            ->set('file', $this->upload(
                '2014-0042,Juan,Santos,,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
            ))
            ->call('commit')
            ->assertHasErrors('file');

        $this->assertSame(0, Employee::count());
    }

    public function test_committing_with_no_file_says_so_instead_of_exploding(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.import')
            ->call('commit')
            ->assertHasErrors('file');

        $this->assertSame(0, Employee::count());
    }

    public function test_a_blocked_import_says_why_rather_than_going_quiet(): void
    {
        // A disabled button that says nothing looks like a broken one.
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.import')
            ->set('file', $this->upload(
                "2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,\n".
                '2015-0100,Maria,Reyes,,,Statistician II,ADMIN,STAT,permanent,2015-01-05,'
            ))
            ->assertSee('Import is blocked')
            ->assertSee('1 row(s) above still have problems');
    }

    public function test_a_clean_preview_shows_no_blocked_notice(): void
    {
        Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.import')
            ->set('file', $this->upload(
                '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,'
            ))
            ->assertDontSee('Import is blocked');
    }

    public function test_a_row_that_became_a_duplicate_after_the_preview_is_caught_at_commit(): void
    {
        // Someone else imported the same employee between the preview and the
        // confirmation. The commit re-parses, so it is caught.
        $component = Livewire::actingAs($this->userWithRole('hr'))
            ->test('pages::employees.import')
            ->set('file', $this->upload(
                '2014-0042,Juan,Santos,Dela Cruz,,Statistician II,ADMIN,STAT,permanent,2014-06-01,1042'
            ))
            ->assertSet('errorCount', 0);

        Employee::factory()->create(['employee_number' => '2014-0042']);

        $component->call('commit')->assertHasErrors('file');

        $this->assertSame(1, Employee::count());
    }
}
