<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Ownership lives here, not in a permission. A permission cannot see which
 * record is being requested, which is exactly how IDOR gets in.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->owns($user, $employee) || $user->can('employees.view');
    }

    /**
     * The employee master belongs to HR. What an employee maintains is their
     * PDS, which is a different question with its own policy.
     */
    public function update(User $user, Employee $employee): bool
    {
        return $user->can('employees.manage');
    }

    /**
     * Same ability as update. Adding a person and correcting one are the same
     * job done by the same office, and a separate permission would be one more
     * thing to forget to grant.
     */
    public function create(User $user): bool
    {
        return $user->can('employees.manage');
    }

    public function import(User $user): bool
    {
        return $user->can('employees.import');
    }

    public function issueAccount(User $user): bool
    {
        return $user->can('users.manage');
    }

    // -----------------------------------------------------------------
    // The Personal Data Sheet
    //
    // These live here rather than in a PdsPolicy because Laravel resolves one
    // policy per model, and the subject of both questions is the same
    // Employee. A second policy class pointing at Employee would never be
    // reached — `authorize('view', $employee)` would quietly keep asking
    // EmployeePolicy, which answers a different question.
    //
    // The abilities are separate from the ones above on purpose: HR editing
    // the employee master is not the same permission as HR correcting
    // somebody's PDS, and an employee may do the second on their own record
    // while never doing the first.
    // -----------------------------------------------------------------

    public function viewPds(User $user, Employee $employee): bool
    {
        return $this->owns($user, $employee) || $user->can('pds.view.any');
    }

    public function updatePds(User $user, Employee $employee): bool
    {
        return $this->owns($user, $employee) || $user->can('pds.edit.any');
    }

    public function exportPds(User $user, Employee $employee): bool
    {
        return $this->owns($user, $employee) || $user->can('pds.export.any');
    }

    /**
     * Every imported row starts with a null user_id. Comparing two nulls would
     * hand the first employee without a login somebody else's record.
     */
    private function owns(User $user, Employee $employee): bool
    {
        return $employee->user_id !== null && $employee->user_id === $user->id;
    }
}
