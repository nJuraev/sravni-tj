<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\BankReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature-тесты отзывов о банках и агрегата рейтинга (премодерация).
 */
class BankReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_approved_reviews(): void
    {
        $bank = Bank::factory()->create();
        BankReview::factory()->for($bank, 'bank')->create(['body' => 'Одобренный отзыв номер раз', 'status' => 'approved']);
        BankReview::factory()->for($bank, 'bank')->pending()->create();
        BankReview::factory()->for($bank, 'bank')->rejected()->create();

        $this->getJson("/api/banks/{$bank->id}/reviews")
            ->assertOk()
            ->assertJsonPath('pagination.total_items', 1)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_404_for_inactive_bank(): void
    {
        $bank = Bank::factory()->inactive()->create();

        $this->getJson("/api/banks/{$bank->id}/reviews")->assertStatus(404);
    }

    public function test_store_creates_pending_review(): void
    {
        $bank = Bank::factory()->create();

        $this->postJson("/api/banks/{$bank->id}/reviews", [
            'author_name' => 'Иван Иванов',
            'rating' => 5,
            'body' => 'Отличный банк, быстро оформили кредит.',
            'consent' => true,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('bank_reviews', [
            'bank_id' => $bank->id,
            'rating' => 5,
            'status' => 'pending',
            'consent' => true,
        ]);
    }

    public function test_store_requires_consent(): void
    {
        $bank = Bank::factory()->create();

        $this->postJson("/api/banks/{$bank->id}/reviews", [
            'author_name' => 'Без Согласия',
            'rating' => 4,
            'body' => 'Текст отзыва достаточной длины.',
            'consent' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['consent']);

        $this->assertDatabaseCount('bank_reviews', 0);
    }

    public function test_store_rejects_invalid_rating(): void
    {
        $bank = Bank::factory()->create();

        $this->postJson("/api/banks/{$bank->id}/reviews", [
            'author_name' => 'Тест',
            'rating' => 9, // вне 1..5
            'body' => 'Текст отзыва достаточной длины.',
            'consent' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['rating']);
    }

    public function test_store_404_for_inactive_bank(): void
    {
        $bank = Bank::factory()->inactive()->create();

        $this->postJson("/api/banks/{$bank->id}/reviews", [
            'author_name' => 'Тест',
            'rating' => 5,
            'body' => 'Текст отзыва достаточной длины.',
            'consent' => true,
        ])->assertStatus(404);
    }

    public function test_bank_rating_aggregate_counts_only_approved(): void
    {
        $bank = Bank::factory()->create(['name_ru' => 'Тестбанк', 'name_tg' => null]);
        BankReview::factory()->for($bank, 'bank')->create(['rating' => 4, 'status' => 'approved']);
        BankReview::factory()->for($bank, 'bank')->create(['rating' => 2, 'status' => 'approved']);
        BankReview::factory()->for($bank, 'bank')->pending()->create(['rating' => 5]); // не учитывается

        $response = $this->getJson('/api/banks')->assertOk();

        $banks = collect($response->json('data'));
        $target = $banks->firstWhere('id', $bank->id);

        $this->assertNotNull($target);
        $this->assertSame(2, $target['rating_count']);
        // Нестрогое сравнение: JSON может вернуть 3 (int) или 3.0 (float).
        $this->assertEqualsWithDelta(3.0, $target['rating_avg'], 0.001); // (4+2)/2
    }
}
