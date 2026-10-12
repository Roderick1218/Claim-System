<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Idempotent: creates missing roles/permissions; only sets a role's permissions when the role is first created.
 * A permission added in a later version is given to its default roles once, when it is first created,
 * so admins' own changes to existing roles are never overwritten.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'staff' => ['Create and manage own claims', []],
            // Company rule: everyone sees only their own claims; only Admin sees everyone's.
            // Wider access can still be granted per role in Settings → Roles & Permissions.
            'manager' => ['Create and manage own claims', []],
            'admin' => ['Manage users, categories, projects, approve claims', [
                Permission::CLAIMS_VIEW_ALL, Permission::CLAIMS_MANAGE_ALL, Permission::CLAIMS_APPROVE, Permission::REPORTS_VIEW,
                ...Permission::settingsPermissions(),
            ]],
            'owner' => ['Create and manage own claims', []],
        ];

        $newPermissions = [];
        foreach (Permission::definitions() as $name => $description) {
            $permission = Permission::firstOrCreate(['name' => $name], ['description' => $description]);
            if ($permission->wasRecentlyCreated) {
                $newPermissions[$name] = $permission->id;
            }
        }

        foreach ($roles as $name => [$description, $permissions]) {
            $role = Role::firstOrCreate(['name' => $name], ['description' => $description]);
            if ($role->wasRecentlyCreated) {
                $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
            } elseif ($add = array_values(array_intersect_key($newPermissions, array_flip($permissions)))) {
                $role->permissions()->syncWithoutDetaching($add);
            }
        }
    }
}
