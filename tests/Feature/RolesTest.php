<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_the_employee_role_holds_no_permissions(): void
    {
        // Ownership is a policy question, not a permission. An employee reaches
        // their own record through EmployeePolicy, never through a grant.
        $this->assertTrue(Role::findByName('employee')->permissions->isEmpty());
    }

    public function test_hr_can_reach_every_pds_but_cannot_manage_roles(): void
    {
        $hr = Role::findByName('hr');

        $this->assertTrue($hr->hasPermissionTo('pds.view.any'));
        $this->assertTrue($hr->hasPermissionTo('employees.import'));
        $this->assertFalse($hr->hasPermissionTo('roles.manage'));
        $this->assertFalse($hr->hasPermissionTo('org.manage'));
    }

    public function test_admin_holds_every_permission(): void
    {
        $admin = Role::findByName('admin');

        foreach (RoleSeeder::PERMISSIONS as $permission) {
            $this->assertTrue(
                $admin->hasPermissionTo($permission),
                "admin is missing [{$permission}]"
            );
        }
    }
}
