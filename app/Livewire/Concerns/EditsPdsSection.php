<?php

namespace App\Livewire\Concerns;

use App\Models\Employee;

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
}
