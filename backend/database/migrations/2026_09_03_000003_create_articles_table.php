<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица articles — блог/новости. Авторский контент (владелец/жена через
 * admin UI), не парсер — модель пишет через $fillable, не forceFill
 * (см. FinancePost/PostTopic, не Product/Bank).
 *
 * status — VARCHAR + CHECK (draft|published), как везде в проекте. category —
 * НЕ CHECK-enum, а FK на article_categories (расширяется без деплоя).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('title_ru', 255);
            $table->string('title_tg', 255)->nullable();

            $table->string('slug', 160)->unique('uq_articles_slug');

            $table->text('excerpt_ru')->nullable();
            $table->text('excerpt_tg')->nullable();

            $table->text('body_ru');
            $table->text('body_tg')->nullable();

            // Путь/URL в отдельном Railway-контейнере хранения, не в БД.
            $table->string('cover_image', 500)->nullable();

            $table->string('youtube_url', 500)->nullable();

            $table->foreignId('category_id')
                ->constrained('article_categories')
                ->restrictOnDelete();

            $table->string('status', 16)->default('draft');

            $table->timestampTz('published_at')->nullable();

            $table->string('author_name', 255)->nullable();

            // FK → banks(id). Удалили банк — ссылка обнуляется, статья остаётся.
            $table->foreignId('related_bank_id')
                ->nullable()
                ->constrained('banks')
                ->nullOnDelete();

            // FK → products(id). Аналогично.
            $table->foreignId('related_product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            $table->timestampsTz();
        });

        Schema::table('articles', function (Blueprint $table) {
            // Публичный список: status='published' AND published_at<=now(), сортировка по дате.
            $table->index(['status', 'published_at'], 'idx_articles_status_published');

            // Фильтр по категории на /blog.
            $table->index('category_id', 'idx_articles_category');
        });

        DB::statement("ALTER TABLE articles ADD CONSTRAINT chk_articles_status CHECK (status IN ('draft','published'))");
        DB::statement("ALTER TABLE articles ADD CONSTRAINT chk_articles_published_has_date CHECK (status <> 'published' OR published_at IS NOT NULL)");
        DB::statement("ALTER TABLE articles ADD CONSTRAINT chk_articles_youtube_domain CHECK (youtube_url IS NULL OR youtube_url ~* '^https?://(www\\.)?(youtube\\.com|youtu\\.be)/')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE articles DROP CONSTRAINT IF EXISTS chk_articles_status');
        DB::statement('ALTER TABLE articles DROP CONSTRAINT IF EXISTS chk_articles_published_has_date');
        DB::statement('ALTER TABLE articles DROP CONSTRAINT IF EXISTS chk_articles_youtube_domain');

        Schema::dropIfExists('articles');
    }
};
