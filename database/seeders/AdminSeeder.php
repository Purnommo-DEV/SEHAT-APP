<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public const EMAIL = 'admin@sehat-app.test';

    public const PASSWORD = 'password';

    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
    }
}
