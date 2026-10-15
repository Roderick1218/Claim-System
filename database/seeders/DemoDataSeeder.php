<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/** 本地测试用的 demo 账号，密码全是 password123，上线前一定要改密码或停用 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $departments = collect(['Management', 'Sales', 'Operations', 'Accounts'])
            ->mapWithKeys(fn ($name) => [$name => Department::firstOrCreate(['name' => $name])]);

        $users = [
            ['Owner Demo', 'owner@claim.local', 'E0001', 'Management', 'owner'],
            ['Admin Demo', 'admin@claim.local', 'E0002', 'Accounts', 'admin'],
            ['Manager Sales', 'manager@claim.local', 'E0003', 'Sales', 'manager'],
            ['Staff Sales', 'staff@claim.local', 'E0004', 'Sales', 'staff'],
            ['Staff Operations', 'staff2@claim.local', 'E0005', 'Operations', 'staff'],
        ];

        foreach ($users as [$name, $email, $code, $dept, $role]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'employee_code' => $code,
                'password' => 'password123',
                'department_id' => $departments[$dept]->id,
                'is_active' => true,
            ]);
            $user->roles()->syncWithoutDetaching([Role::where('name', $role)->value('id')]);
        }

        Project::firstOrCreate(['project_code' => 'PRJ-001'], ['name' => 'Demo Project A', 'customer_name' => 'Demo Customer Sdn Bhd']);
        Project::firstOrCreate(['project_code' => 'PRJ-002'], ['name' => 'Demo Project B']);
    }
}
