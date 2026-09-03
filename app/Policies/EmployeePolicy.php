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

    public function import(User $user): bool
    {
        return $user->can('employees.import');
    }

    public function issueAccount(User $user): bool
    {
        return $user->can('users.manage');
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
