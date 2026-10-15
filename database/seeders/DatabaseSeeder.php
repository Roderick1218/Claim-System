<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class, // production 可重复跑
            ClaimCategorySeeder::class,  // production 可重复跑
            DemoDataSeeder::class,       // 只限本地 / demo
        ]);
    }
}
