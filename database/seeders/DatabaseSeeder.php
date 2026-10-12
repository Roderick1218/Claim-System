<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class, // safe to re-run on production
            ClaimCategorySeeder::class,  // safe to re-run on production
            DemoDataSeeder::class,       // local / demo only
        ]);
    }
}
