<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Базовый администратор админ-панели.
 *
 * Идемпотентно (updateOrInsert по email). Дефолтные креды для MVP/локалки:
 *   email:    admin@sravni.tj
 *   password: admin12345
 * Смените пароль после первого входа (раздел «Пользователи»).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('admin_users')->updateOrInsert(
            ['email' => 'admin@sravni.tj'],
            [
                'name' => 'Администратор',
                'password' => Hash::make('admin12345'),
                'role' => 'admin',
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
}
