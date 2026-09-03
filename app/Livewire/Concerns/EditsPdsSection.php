<?php

namespace App\Livewire\Concerns;

use App\Models\Employee;
use App\Services\AuditRecorder;

/**
 * Every PDS section answers the same two questions before it renders: whose
 * PDS is this, and may this person see it. Nine sections asking it nine
 * different ways is nine chances to get it wrong once.
 *
 * The employee is deliberately not held as a property. Only its id is, and
 * that is re-resolved and re-authorised on every request — a model surviving
 * between requests is a model the browser can rewrite.
 */
trait EditsPdsSection
{
    public ?int $employeeId = null;

    public function resolveEmployee(): Employee
    {
        $employee = $this->employeeId !== null
            ? Employee::find($this->employeeId)
            : auth()->user()?->employee;

        abort_if($employee === null, 403, 'This account is not linked to an employee record.');

        return $employee;
    }

    /** Call this first in mount(). */
    protected function bootSection(?int $employeeId): Employee
    {
        $this->employeeId = $employeeId;

        $employee = $this->resolveEmployee();

        $this->authorize('viewPds', $employee);

        // Keep the resolved id, so a later save cannot silently target a
        // different record than the one that was authorised here.
        $this->employeeId = $employee->id;

        $this->recordReadIfSomebodyElses($employee);

        return $employee;
    }

    /**
     * Call this first in every save.
     *
     * This is not redundant with bootSection(). mount() runs once; every later
     * request rehydrates $employeeId from the browser, where it can be changed
     * to anything. Authorising only on mount protects the first page view and
     * nothing after it.
     */
    protected function authoriseSave(): Employee
    {
        $employee = $this->resolveEmployee();

        $this->authorize('updatePds', $employee);

        return $employee;
    }

    /**
     * An employee opening their own PDS is not worth recording — it would bury
     * the entries that matter under thousands that do not. Somebody else
     * opening it is the whole reason the audit log exists: edits are rare, and
     * looking up a colleague's home address or their answer to item 35 is not.
     */
    private function recordReadIfSomebodyElses(Employee $employee): void
    {
        if ($employee->user_id === auth()->id()) {
            return;
        }

        app(AuditRecorder::class)->recordRead(
            $employee,
            'Opened the PDS section: '.$this->pdsSectionName(),
        );
    }

    /** "pages::pds.work-experience" reads better in a log than a class hash. */
    private function pdsSectionName(): string
    {
        return str(request()->route()?->getName() ?? static::class)
            ->afterLast('pds.')
            ->replace('-', ' ')
            ->toString();
    }
}
