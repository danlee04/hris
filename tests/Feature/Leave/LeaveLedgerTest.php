<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\User;
use App\Services\Leave\LeaveLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private LeaveLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->create();
        $this->ledger = app(LeaveLedger::class);
    }

    public function test_an_opening_balance_is_the_balance(): void
    {
        $this->ledger->open($this->employee, 'vacation', 12.5);

        $this->assertSame(12.5, $this->ledger->balance($this->employee, 'vacation'));
    }

    public function test_two_ledgers_do_not_mix(): void
    {
        // Vacation and sick are separate balances on the same form, and an
        // entry landing in the wrong one would still add up.
        $this->ledger->open($this->employee, 'vacation', 10);
        $this->ledger->open($this->employee, 'sick', 4);

        $this->assertSame(10.0, $this->ledger->balance($this->employee, 'vacation'));
        $this->assertSame(4.0, $this->ledger->balance($this->employee, 'sick'));
    }

    public function test_two_employees_do_not_mix(): void
    {
        $other = Employee::factory()->create();

        $this->ledger->open($this->employee, 'vacation', 10);

        $this->assertSame(0.0, $this->ledger->balance($other, 'vacation'));
    }

    public function test_an_accrual_adds_to_the_balance(): void
    {
        $this->ledger->open($this->employee, 'vacation', 10);
        $this->ledger->accrue($this->employee, 'vacation', 1.25, '2026-09');

        $this->assertSame(11.25, $this->ledger->balance($this->employee, 'vacation'));
    }

    public function test_the_same_period_cannot_accrue_twice(): void
    {
        // Posting a month is a button somebody will press twice. The second
        // press must write nothing rather than hand out a second 1.25.
        $this->ledger->accrue($this->employee, 'vacation', 1.25, '2026-09');
        $second = $this->ledger->accrue($this->employee, 'vacation', 1.25, '2026-09');

        $this->assertNull($second);
        $this->assertSame(1.25, $this->ledger->balance($this->employee, 'vacation'));
        $this->assertSame(1, LeaveLedgerEntry::count());
    }

    public function test_a_different_period_accrues_again(): void
    {
        $this->ledger->accrue($this->employee, 'vacation', 1.25, '2026-09');
        $this->ledger->accrue($this->employee, 'vacation', 1.25, '2026-10');

        $this->assertSame(2.5, $this->ledger->balance($this->employee, 'vacation'));
    }

    public function test_a_yearly_grant_is_keyed_on_the_year(): void
    {
        $this->ledger->grant($this->employee, 'wellness', 5, '2026');

        $this->assertNull($this->ledger->grant($this->employee, 'wellness', 5, '2026'));
        $this->assertSame(5.0, $this->ledger->balance($this->employee, 'wellness'));

        $this->ledger->grant($this->employee, 'wellness', 5, '2027');

        $this->assertSame(10.0, $this->ledger->balance($this->employee, 'wellness'));
    }

    public function test_an_adjustment_can_go_either_way_and_keeps_its_reason(): void
    {
        $this->ledger->open($this->employee, 'vacation', 10);
        $this->ledger->adjust($this->employee, 'vacation', -2, 'Corrected from the 2025 spreadsheet');

        $this->assertSame(8.0, $this->ledger->balance($this->employee, 'vacation'));

        $entry = LeaveLedgerEntry::latest('id')->first();

        $this->assertSame('Corrected from the 2025 spreadsheet', $entry->description);
    }

    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        // An unexplained change to somebody's leave balance is the entry a
        // person will ask about a year later. It does not get to be silent.
        $this->expectException(ValidationException::class);

        $this->ledger->adjust($this->employee, 'vacation', -2, '   ');
    }

    public function test_a_second_opening_balance_is_refused(): void
    {
        // The opening balance is what was carried in from the spreadsheet. A
        // second one is a correction, and a correction is an adjustment with a
        // reason attached.
        $this->ledger->open($this->employee, 'vacation', 10);

        $this->expectException(ValidationException::class);

        $this->ledger->open($this->employee, 'vacation', 12);
    }

    public function test_every_entry_records_who_wrote_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->ledger->open($this->employee, 'vacation', 10);

        $this->assertSame($user->id, LeaveLedgerEntry::sole()->created_by_user_id);
    }

    public function test_entries_are_never_updated_only_added(): void
    {
        // The ledger is the answer to "where did my credits go". An entry that
        // changed after the fact cannot answer it.
        $this->ledger->open($this->employee, 'vacation', 10);
        $this->ledger->adjust($this->employee, 'vacation', 3, 'Awarded');

        $this->assertSame(2, LeaveLedgerEntry::count());
        $this->assertSame(13.0, $this->ledger->balance($this->employee, 'vacation'));
    }
}
