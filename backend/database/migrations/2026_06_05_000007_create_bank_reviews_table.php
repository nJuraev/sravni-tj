<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица bank_reviews — отзывы пользователей о банках (премодерация).
 *
 * Новый отзыв создаётся со status='pending' и попадает в публичную выдачу и в
 * агрегат рейтинга банка только после перевода в 'approved' (вручную через БД —
 * админки на MVP нет). Рейтинг банка = AVG(rating)/COUNT по approved-отзывам.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reviews', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('bank_id')
                ->constrained('banks')
                ->cascadeOnDelete();

            $table->string('author_name', 120);
            $table->smallInteger('rating'); // 1..5 (CHECK ниже)
            $table->text('body');

            // Согласие на обработку ПД — обязательно true (CHECK).
            $table->boolean('consent');

            // pending|approved|rejected (VARCHAR + CHECK). Премодерация → старт 'pending'.
            $table->string('status', 16)->default('pending');

            $table->timestampsTz();
        });

        Schema::table('bank_reviews', function (Blueprint $table) {
            // Основная выборка: одобренные отзывы банка.
            $table->index(['bank_id', 'status'], 'idx_reviews_bank_status');
        });

        DB::statement('ALTER TABLE bank_reviews ADD CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE bank_reviews ADD CONSTRAINT chk_reviews_status CHECK (status IN ('pending','approved','rejected'))");
        DB::statement('ALTER TABLE bank_reviews ADD CONSTRAINT chk_reviews_consent CHECK (consent = true)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_reviews DROP CONSTRAINT IF EXISTS chk_reviews_rating');
        DB::statement('ALTER TABLE bank_reviews DROP CONSTRAINT IF EXISTS chk_reviews_status');
        DB::statement('ALTER TABLE bank_reviews DROP CONSTRAINT IF EXISTS chk_reviews_consent');

        Schema::dropIfExists('bank_reviews');
    }
};
