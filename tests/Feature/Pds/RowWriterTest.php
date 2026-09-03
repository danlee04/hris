<?php

namespace Tests\Feature\Pds;

use App\Models\Employee;
use App\Models\Pds\Child;
use App\Services\Pds\RowWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RowWriterTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->create();
    }

    public function test_it_creates_rows_in_the_order_they_were_given(): void
    {
        app(RowWriter::class)->sync(Child::class, $this->employee->id, [
            ['id' => null, 'name' => 'Ana', 'date_of_birth' => '2010-01-05'],
            ['id' => null, 'name' => 'Ben', 'date_of_birth' => '2012-06-11'],
        ]);

        $children = Child::where('employee_id', $this->employee->id)->orderBy('sort_order')->get();

        $this->assertSame(['Ana', 'Ben'], $children->pluck('name')->all());
        $this->assertSame([0, 1], $children->pluck('sort_order')->all());
    }

    public function test_it_updates_a_row_that_carries_an_id(): void
    {
        $child = Child::factory()->create(['employee_id' => $this->employee->id, 'name' => 'Ana']);

        app(RowWriter::class)->sync(Child::class, $this->employee->id, [
            ['id' => $child->id, 'name' => 'Ana Marie', 'date_of_birth' => '2010-01-05'],
        ]);

        $this->assertSame(1, Child::count());
        $this->assertSame('Ana Marie', $child->refresh()->name);
    }

    public function test_it_deletes_rows_that_are_no_longer_in_the_list(): void
    {
        $kept = Child::factory()->create(['employee_id' => $this->employee->id, 'name' => 'Ana']);
        Child::factory()->create(['employee_id' => $this->employee->id, 'name' => 'Ben']);

        app(RowWriter::class)->sync(Child::class, $this->employee->id, [
            ['id' => $kept->id, 'name' => 'Ana', 'date_of_birth' => null],
        ]);

        $this->assertSame(1, Child::count());
        $this->assertTrue(Child::first()->is($kept));
    }

    public function test_an_empty_list_removes_everything(): void
    {
        Child::factory()->count(3)->create(['employee_id' => $this->employee->id]);

        app(RowWriter::class)->sync(Child::class, $this->employee->id, []);

        $this->assertSame(0, Child::count());
    }

    public function test_it_leaves_another_employees_rows_alone(): void
    {
        $theirs = Child::factory()->create(['employee_id' => Employee::factory()->create()->id]);

        app(RowWriter::class)->sync(Child::class, $this->employee->id, []);

        $this->assertTrue($theirs->exists());
        $this->assertSame(1, Child::count());
    }

    public function test_it_refuses_a_row_id_belonging_to_another_employee(): void
    {
        // The row id travels to the browser and comes back. Without this check,
        // editing your own children could rewrite somebody else's.
        $someoneElse = Child::factory()->create(['employee_id' => Employee::factory()->create()->id]);

        $this->expectException(AuthorizationException::class);

        app(RowWriter::class)->sync(Child::class, $this->employee->id, [
            ['id' => $someoneElse->id, 'name' => 'Hijacked', 'date_of_birth' => null],
        ]);
    }

    public function test_a_refused_sync_writes_nothing(): void
    {
        $mine = Child::factory()->create(['employee_id' => $this->employee->id, 'name' => 'Ana']);
        $someoneElse = Child::factory()->create(['employee_id' => Employee::factory()->create()->id]);

        try {
            app(RowWriter::class)->sync(Child::class, $this->employee->id, [
                ['id' => $mine->id, 'name' => 'Changed', 'date_of_birth' => null],
                ['id' => $someoneElse->id, 'name' => 'Hijacked', 'date_of_birth' => null],
            ]);
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame('Ana', $mine->refresh()->name);
    }

    public function test_a_row_cannot_smuggle_in_a_different_employee_id(): void
    {
        // employee_id is decided by the caller, never by the row.
        app(RowWriter::class)->sync(Child::class, $this->employee->id, [
            ['id' => null, 'employee_id' => Employee::factory()->create()->id, 'name' => 'Ana'],
        ]);

        $this->assertSame($this->employee->id, Child::first()->employee_id);
    }
}
