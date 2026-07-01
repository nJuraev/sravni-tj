<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Product;
use App\Models\ProductRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature-тесты витрины, разнесённой по типам продукта:
 *  GET /api/products/{credits|deposits|installments}, /api/products/{id}.
 * Соответствуют backend.md §8 и инвариантам contracts.md.
 */
class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_credits_returns_only_active_products_with_active_bank(): void
    {
        $activeBank = Bank::factory()->create();
        $inactiveBank = Bank::factory()->inactive()->create();

        $visible = Product::factory()->for($activeBank, 'bank')->credit()->create(['status' => 'active']);
        Product::factory()->for($activeBank, 'bank')->credit()->draft()->create();
        Product::factory()->for($activeBank, 'bank')->credit()->hidden()->create();
        Product::factory()->for($inactiveBank, 'bank')->credit()->create(['status' => 'active']);

        $response = $this->getJson('/api/products/credits');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $visible->id)
            ->assertJsonPath('pagination.total_items', 1);
    }

    public function test_credits_endpoint_returns_only_credits(): void
    {
        $bank = Bank::factory()->create();
        $credit = Product::factory()->for($bank, 'bank')->credit()->create();
        Product::factory()->for($bank, 'bank')->deposit()->create();
        Product::factory()->for($bank, 'bank')->installment()->create();

        $this->getJson('/api/products/credits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $credit->id);
    }

    public function test_deposits_endpoint_returns_only_deposits(): void
    {
        $bank = Bank::factory()->create();
        Product::factory()->for($bank, 'bank')->credit()->create();
        $deposit = Product::factory()->for($bank, 'bank')->deposit()->create();

        $this->getJson('/api/products/deposits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $deposit->id);
    }

    public function test_installments_endpoint_returns_only_installments(): void
    {
        $bank = Bank::factory()->create();
        Product::factory()->for($bank, 'bank')->credit()->create();
        $installment = Product::factory()->for($bank, 'bank')->installment()->create();

        $this->getJson('/api/products/installments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $installment->id);
    }

    public function test_credits_sorted_by_rate_ascending_by_default(): void
    {
        $bank = Bank::factory()->create();
        $expensive = Product::factory()->for($bank, 'bank')->credit()->create(['rate_min' => 25, 'rate_max' => 30]);
        $cheap = Product::factory()->for($bank, 'bank')->credit()->create(['rate_min' => 5, 'rate_max' => 9]);

        $this->getJson('/api/products/credits')
            ->assertOk()
            ->assertJsonPath('data.0.id', (int) $cheap->id)
            ->assertJsonPath('data.1.id', (int) $expensive->id);
    }

    public function test_deposits_sorted_by_rate_descending_by_default(): void
    {
        $bank = Bank::factory()->create();
        $low = Product::factory()->for($bank, 'bank')->deposit()->create(['rate_min' => 5, 'rate_max' => 8]);
        $high = Product::factory()->for($bank, 'bank')->deposit()->create(['rate_min' => 10, 'rate_max' => 18]);

        $this->getJson('/api/products/deposits')
            ->assertOk()
            ->assertJsonPath('data.0.id', (int) $high->id)
            ->assertJsonPath('data.1.id', (int) $low->id);
    }

    public function test_special_products_hidden_by_default(): void
    {
        $bank = Bank::factory()->create();
        $normal = Product::factory()->for($bank, 'bank')->credit()->create();
        Product::factory()->for($bank, 'bank')->credit()->special()->create();

        $this->getJson('/api/products/credits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $normal->id);
    }

    public function test_special_products_shown_when_requested(): void
    {
        $bank = Bank::factory()->create();
        Product::factory()->for($bank, 'bank')->credit()->create();
        Product::factory()->for($bank, 'bank')->credit()->special()->create();

        $this->getJson('/api/products/credits?special=true')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_product_json_matches_contract_shape(): void
    {
        $bank = Bank::factory()->partner()->create(['name_tg' => null]);
        $product = Product::factory()->deposit()->for($bank, 'bank')->create([
            'currency' => 'TJS',
            'subcategory' => 'term',
            'features' => ['online_application' => true, 'replenishable' => true],
        ]);
        ProductRate::factory()->for($product, 'product')
            ->tier(3, 11, 5000, 50000, 10.0)->create();

        $response = $this->getJson('/api/products/'.$product->id);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'category', 'subcategory', 'is_special', 'currency', 'name_ru', 'name_tg',
                    'description_ru', 'description_tg',
                    'rate_min', 'rate_max', 'amount_min', 'amount_max',
                    'term_min', 'term_max',
                    'rate_tiers' => [['currency', 'amount_from', 'amount_to', 'term_from', 'term_to', 'rate']],
                    'features' => ['online_application', 'no_guarantor', 'capitalization', 'replenishment'],
                    'bank' => ['id', 'name_ru', 'name_tg', 'is_partner'],
                    'parsed_at',
                ],
            ])
            // features: replenishment маппится из replenishable; неизвестное → false.
            ->assertJsonPath('data.subcategory', 'term')
            ->assertJsonPath('data.is_special', false)
            ->assertJsonPath('data.features.online_application', true)
            ->assertJsonPath('data.features.replenishment', true)
            ->assertJsonPath('data.features.no_guarantor', false)
            ->assertJsonPath('data.rate_tiers.0.currency', 'TJS')
            // email/status банка наружу не отдаются.
            ->assertJsonMissingPath('data.bank.email')
            ->assertJsonMissingPath('data.bank.status');
    }

    public function test_show_returns_404_for_hidden_product(): void
    {
        $bank = Bank::factory()->create();
        $hidden = Product::factory()->for($bank, 'bank')->hidden()->create();

        $this->getJson('/api/products/'.$hidden->id)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_show_returns_404_when_bank_inactive(): void
    {
        $bank = Bank::factory()->inactive()->create();
        $product = Product::factory()->for($bank, 'bank')->create(['status' => 'active']);

        $this->getJson('/api/products/'.$product->id)->assertNotFound();
    }

    public function test_show_returns_404_for_missing_product(): void
    {
        $this->getJson('/api/products/999999')->assertNotFound();
    }

    public function test_filters_by_currency(): void
    {
        $bank = Bank::factory()->create();
        $match = Product::factory()->for($bank, 'bank')->deposit()->create(['currency' => 'USD']);
        Product::factory()->for($bank, 'bank')->deposit()->create(['currency' => 'TJS']);

        $this->getJson('/api/products/deposits?currency=USD')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $match->id);
    }

    public function test_amount_filter_uses_range_intersection(): void
    {
        $bank = Bank::factory()->create();
        // Продукт доступен для суммы 20000 (10000..50000).
        $inRange = Product::factory()->for($bank, 'bank')->credit()->create([
            'amount_min' => 10000, 'amount_max' => 50000,
        ]);
        // Минимум выше запрошенной суммы — не проходит.
        Product::factory()->for($bank, 'bank')->credit()->create([
            'amount_min' => 30000, 'amount_max' => 80000,
        ]);

        $this->getJson('/api/products/credits?amount_min=20000&amount_max=20000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $inRange->id);
    }

    public function test_rate_filter_uses_aggregate_intersection(): void
    {
        $bank = Bank::factory()->create();
        $match = Product::factory()->for($bank, 'bank')->credit()->create(['rate_min' => 10, 'rate_max' => 16]);
        Product::factory()->for($bank, 'bank')->credit()->create(['rate_min' => 20, 'rate_max' => 25]);

        $this->getJson('/api/products/credits?rate_min=12&rate_max=14')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $match->id);
    }

    public function test_filters_by_subcategory_multiselect(): void
    {
        $bank = Bank::factory()->create();
        $consumer = Product::factory()->for($bank, 'bank')->credit()->create(['subcategory' => 'consumer']);
        $auto = Product::factory()->for($bank, 'bank')->credit()->create(['subcategory' => 'auto']);
        Product::factory()->for($bank, 'bank')->credit()->create(['subcategory' => 'mortgage']);

        $response = $this->getJson('/api/products/credits?subcategory[]=consumer&subcategory[]=auto')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $ids = array_column($response->json('data'), 'id');
        sort($ids);
        $this->assertSame([(int) $consumer->id, (int) $auto->id], $ids);
    }

    public function test_filters_by_bank_multiselect(): void
    {
        $bankA = Bank::factory()->create();
        $bankB = Bank::factory()->create();
        $bankC = Bank::factory()->create();
        $a = Product::factory()->for($bankA, 'bank')->credit()->create();
        $b = Product::factory()->for($bankB, 'bank')->credit()->create();
        Product::factory()->for($bankC, 'bank')->credit()->create();

        $response = $this->getJson('/api/products/credits?bank_id[]='.$bankA->id.'&bank_id[]='.$bankB->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $ids = array_column($response->json('data'), 'id');
        sort($ids);
        $this->assertSame([(int) $a->id, (int) $b->id], $ids);
    }

    public function test_invalid_subcategory_returns_422(): void
    {
        $this->getJson('/api/products/credits?subcategory[]=spaceship')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subcategory.0']);
    }

    public function test_features_filter_requires_all_features(): void
    {
        $bank = Bank::factory()->create();
        $match = Product::factory()->for($bank, 'bank')->credit()->create([
            'features' => ['online_application' => true, 'replenishable' => true],
        ]);
        Product::factory()->for($bank, 'bank')->credit()->create([
            'features' => ['online_application' => true, 'replenishable' => false],
        ]);

        $this->getJson('/api/products/credits?features[]=online_application&features[]=replenishment')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $match->id);
    }

    public function test_exact_tier_mode_filters_by_grid_cell(): void
    {
        $bank = Bank::factory()->create();

        // Продукт с тиром, попадающим в сумму 20000 × срок 12 × ставку ~13.
        $match = Product::factory()->for($bank, 'bank')->credit()->create([
            'currency' => 'TJS', 'rate_min' => 10, 'rate_max' => 16,
            'amount_min' => 5000, 'amount_max' => null, 'term_min' => 3, 'term_max' => 36,
        ]);
        ProductRate::factory()->for($match, 'product')->tier(12, 36, 5000, 50000, 13.0)->create();

        // Агрегаты совпадают, но нет тира под точную ячейку (ставка тира 16.5).
        $noTier = Product::factory()->for($bank, 'bank')->credit()->create([
            'currency' => 'TJS', 'rate_min' => 10, 'rate_max' => 16.5,
            'amount_min' => 5000, 'amount_max' => null, 'term_min' => 3, 'term_max' => 36,
        ]);
        ProductRate::factory()->for($noTier, 'product')->tier(12, 36, 60000, null, 16.5)->create();

        $this->getJson('/api/products/credits?currency=TJS&amount_min=20000&term_min=12&rate_min=12&rate_max=14')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $match->id);
    }

    public function test_invalid_currency_returns_422(): void
    {
        $this->getJson('/api/products/credits?currency=GBP')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_invalid_sort_returns_422(): void
    {
        $this->getJson('/api/products/credits?sort=foo')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_rate_max_less_than_rate_min_returns_422(): void
    {
        $this->getJson('/api/products/credits?rate_min=10&rate_max=5')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rate_max']);
    }

    public function test_empty_result_returns_valid_pagination(): void
    {
        $this->getJson('/api/products/credits')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('pagination.total_items', 0)
            ->assertJsonPath('pagination.total_pages', 0);
    }

    public function test_explicit_sort_overrides_default(): void
    {
        $bank = Bank::factory()->create();
        $low = Product::factory()->for($bank, 'bank')->credit()->create(['rate_min' => 5, 'rate_max' => 8]);
        $high = Product::factory()->for($bank, 'bank')->credit()->create(['rate_min' => 5, 'rate_max' => 20]);

        $this->getJson('/api/products/credits?sort=-rate_max')
            ->assertOk()
            ->assertJsonPath('data.0.id', (int) $high->id)
            ->assertJsonPath('data.1.id', (int) $low->id);
    }
}
