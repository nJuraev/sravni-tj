<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LeadReceived;
use App\Models\Bank;
use App\Models\BankSourceUrl;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature-тесты POST /api/leads (backend.md §8, contracts.md).
 *
 * Адрес доставки заявки берётся из bank_source_urls.email (по категории
 * продукта), а НЕ из общего контакта банка.
 */
class LeadStoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Создаёт видимый продукт с источником, у которого задан email доставки.
     *
     * @param  array<string, mixed>  $bankState
     */
    private function visibleProduct(array $bankState = [], ?string $deliveryEmail = 'leads@example.com'): Product
    {
        $bank = Bank::factory()->state($bankState)->create();

        $source = BankSourceUrl::factory()->for($bank, 'bank')->create([
            'category' => 'credit',
            'email' => $deliveryEmail,
        ]);

        return Product::factory()->for($bank, 'bank')->create([
            'status' => 'active',
            'category' => 'credit',
            'source_url_id' => $source->id,
        ]);
    }

    public function test_valid_lead_is_stored_and_email_queued_to_source_email(): void
    {
        Mail::fake();
        $product = $this->visibleProduct();

        $response = $this->postJson('/api/leads', [
            'full_name' => 'Иван Иванов',
            'phone' => '+992 90 123 45 67',
            'product_id' => $product->id,
            'consent' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.product_id', (int) $product->id)
            ->assertJsonPath('data.bank_id', (int) $product->bank_id)
            ->assertJsonPath('data.consent', true)
            // Телефон нормализован на сервере.
            ->assertJsonPath('data.phone', '+992901234567')
            ->assertJsonPath('message', 'Заявка принята.');

        $this->assertDatabaseHas('leads', [
            'product_id' => $product->id,
            'bank_id' => $product->bank_id,
            'phone' => '+992901234567',
            'consent' => true,
        ]);

        Mail::assertQueued(LeadReceived::class, function (LeadReceived $mail) {
            return $mail->hasTo('leads@example.com');
        });
    }

    public function test_bank_id_is_resolved_server_side_and_ignores_client_value(): void
    {
        Mail::fake();
        $product = $this->visibleProduct();
        $otherBank = Bank::factory()->create();

        $this->postJson('/api/leads', [
            'full_name' => 'Тест Тестов',
            'phone' => '+992900000000',
            'product_id' => $product->id,
            'consent' => true,
            'bank_id' => $otherBank->id, // попытка подмены
        ])->assertCreated()
            ->assertJsonPath('data.bank_id', (int) $product->bank_id);

        $this->assertDatabaseMissing('leads', ['bank_id' => $otherBank->id]);
    }

    public function test_is_partner_does_not_change_delivery_address(): void
    {
        Mail::fake();
        $product = $this->visibleProduct(['is_partner' => true]);

        $this->postJson('/api/leads', [
            'full_name' => 'Партнёр Тест',
            'phone' => '+992900000001',
            'product_id' => $product->id,
            'consent' => true,
        ])->assertCreated();

        Mail::assertQueued(LeadReceived::class, fn (LeadReceived $mail) => $mail->hasTo('leads@example.com'));
    }

    public function test_consent_false_returns_422_and_does_not_store_or_send(): void
    {
        Mail::fake();
        $product = $this->visibleProduct();

        $this->postJson('/api/leads', [
            'full_name' => 'Без Согласия',
            'phone' => '+992900000002',
            'product_id' => $product->id,
            'consent' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['consent']);

        $this->assertDatabaseCount('leads', 0);
        Mail::assertNothingQueued();
    }

    public function test_missing_consent_returns_422(): void
    {
        $product = $this->visibleProduct();

        $this->postJson('/api/leads', [
            'full_name' => 'Нет Поля',
            'phone' => '+992900000003',
            'product_id' => $product->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['consent']);
    }

    public function test_lead_on_hidden_product_returns_422(): void
    {
        $bank = Bank::factory()->create();
        $hidden = Product::factory()->for($bank, 'bank')->hidden()->create();

        $this->postJson('/api/leads', [
            'full_name' => 'Скрытый Продукт',
            'phone' => '+992900000004',
            'product_id' => $hidden->id,
            'consent' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['product_id']);
    }

    public function test_lead_on_product_of_inactive_bank_returns_422(): void
    {
        $bank = Bank::factory()->inactive()->create();
        $product = Product::factory()->for($bank, 'bank')->create(['status' => 'active']);

        $this->postJson('/api/leads', [
            'full_name' => 'Неактивный Банк',
            'phone' => '+992900000005',
            'product_id' => $product->id,
            'consent' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['product_id']);
    }

    public function test_missing_required_fields_returns_422(): void
    {
        $this->postJson('/api/leads', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name', 'phone', 'product_id', 'consent']);
    }

    public function test_lead_saved_when_delivery_email_empty_still_returns_201(): void
    {
        // Источник без email доставки → лид сохраняется (201), письмо не ставится в очередь.
        Mail::fake();
        $product = $this->visibleProduct(deliveryEmail: null);

        $this->postJson('/api/leads', [
            'full_name' => 'Граница Email',
            'phone' => '+992900000006',
            'product_id' => $product->id,
            'consent' => true,
        ])->assertCreated();

        $this->assertDatabaseCount('leads', 1);
        Mail::assertNothingQueued();
    }
}
