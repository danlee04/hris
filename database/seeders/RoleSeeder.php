<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Three roles. Ownership is deliberately absent from this list: an employee
 * reaching their own record is a question about a specific record, which a
 * permission cannot see. That belongs in EmployeePolicy.
 */
class RoleSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'employees.view',
        'employees.manage',
        'employees.import',
        'pds.view.any',
        'pds.edit.any',
        'pds.export.any',
        'org.manage',
        'users.manage',
        'roles.manage',
        'audit.view',
        'leave.manage',
        'leave.types.manage',
    ];

    /** @var list<string> */
    public const HR_PERMISSIONS = [
        'employees.view',
        'employees.manage',
        'employees.import',
        'pds.view.any',
        'pds.edit.any',
        'pds.export.any',
        'audit.view',
        // Balances and applications, but not the vocabulary itself — the same
        // split as org.manage.
        'leave.manage',
    ];

    public function run(): void
    {
        // Spatie caches permissions for 24 hours. Without this, a newly seeded
        // permission stays invisible to the application until that expires.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // syncPermissions sets the exact list, so removing a permission here
        // actually removes it. givePermissionTo would only ever add.
        Role::findOrCreate('employee', 'web')->syncPermissions([]);
        Role::findOrCreate('hr', 'web')->syncPermissions(self::HR_PERMISSIONS);
        Role::findOrCreate('admin', 'web')->syncPermissions(self::PERMISSIONS);
    }
}
