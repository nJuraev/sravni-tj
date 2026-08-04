<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расширяет users под telegram-регистрацию (без пароля): идентичность —
 * telegram_id, сессия — api_token (token-guard, как у admin_users).
 * email/password становятся необязательными — telegram-пользователь
 * их не задаёт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('telegram_id')->nullable()->unique('uq_users_telegram_id')->after('id');
            $table->string('telegram_username', 255)->nullable()->after('telegram_id');
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('api_token', 80)->nullable()->unique('uq_users_api_token')->after('password');
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
        DB::statement('ALTER TABLE users ADD CONSTRAINT chk_users_identity CHECK (email IS NOT NULL OR telegram_id IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_identity');
        DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN password SET NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_id', 'telegram_username', 'phone', 'api_token']);
        });
    }
};
