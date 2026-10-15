<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * 可以重复跑。缺的 role / permission 才会建，role 的权限只在第一次建立时设定。
 * 之后版本新增的 permission，只在它第一次被建立时补给默认的 role 一次，
 * admin 自己在后台调过的 role 权限不会被盖掉。
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'staff' => ['Create and manage own claims', []],
            // 公司规定：每个人只看得到自己的 claim，只有 Admin 能看全部。
            // 真的需要的话，可以在 Settings → Roles & Permissions 按 role 另外开权限。
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
