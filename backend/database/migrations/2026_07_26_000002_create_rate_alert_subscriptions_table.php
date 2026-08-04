<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Подписка пользователя на уведомление о курсе: category/currency/op —
 * какую сторону bank_currency_rates сравнивать с threshold, direction —
 * с какой стороны порога (above: лучший курс ≥ порога; below: ≤ порога).
 * Уведомление шлётся только при выполнении условия И изменении значения
 * с прошлого раза (last_notified_value) — не при каждом прогоне парсера.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_alert_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('category', 16);
            $table->string('currency', 3);
            $table->string('op', 4);
            $table->string('direction', 5);
            $table->decimal('threshold', 12, 4);

            $table->decimal('last_notified_value', 12, 4)->nullable();
            $table->timestampTz('last_notified_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
        });

        Schema::table('rate_alert_subscriptions', function (Blueprint $table) {
            $table->unique(['user_id', 'category', 'currency', 'op', 'direction'], 'uq_ras_key');
        });

        DB::statement("ALTER TABLE rate_alert_subscriptions ADD CONSTRAINT chk_ras_category CHECK (category IN ('cash','transfer'))");
        DB::statement("ALTER TABLE rate_alert_subscriptions ADD CONSTRAINT chk_ras_op CHECK (op IN ('buy','sell'))");
        DB::statement("ALTER TABLE rate_alert_subscriptions ADD CONSTRAINT chk_ras_direction CHECK (direction IN ('above','below'))");
        DB::statement('ALTER TABLE rate_alert_subscriptions ADD CONSTRAINT chk_ras_threshold_positive CHECK (threshold > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rate_alert_subscriptions DROP CONSTRAINT IF EXISTS chk_ras_threshold_positive');
        DB::statement('ALTER TABLE rate_alert_subscriptions DROP CONSTRAINT IF EXISTS chk_ras_direction');
        DB::statement('ALTER TABLE rate_alert_subscriptions DROP CONSTRAINT IF EXISTS chk_ras_op');
        DB::statement('ALTER TABLE rate_alert_subscriptions DROP CONSTRAINT IF EXISTS chk_ras_category');

        Schema::dropIfExists('rate_alert_subscriptions');
    }
};
