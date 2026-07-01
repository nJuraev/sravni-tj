<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица admin_users — пользователи админ-панели.
 *
 * Аутентификация админки построена на встроенном token-guard Laravel:
 * api_token (plaintext, уникальный) выдаётся при логине и проверяется
 * middleware `auth:admin`. Отдельная таблица от публичной users —
 * админка изолирована от доменных данных.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('name', 255);
            $table->string('email', 255)->unique('uq_admin_users_email');

            // Хеш пароля (bcrypt; cast 'hashed' на модели).
            $table->string('password', 255);

            // Роль: admin (полный доступ) | editor (без управления пользователями).
            $table->string('role', 16)->default('admin');

            // Деактивированный аккаунт не проходит логин.
            $table->boolean('is_active')->default(true);

            // API-токен сессии (token-guard). NULL = разлогинен.
            $table->string('api_token', 80)->nullable()->unique('uq_admin_users_api_token');

            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE admin_users ADD CONSTRAINT chk_admin_users_role CHECK (role IN ('admin','editor'))");
        DB::statement("ALTER TABLE admin_users ADD CONSTRAINT chk_admin_users_email_format CHECK (email ~* '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS chk_admin_users_role');
        DB::statement('ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS chk_admin_users_email_format');

        Schema::dropIfExists('admin_users');
    }
};
