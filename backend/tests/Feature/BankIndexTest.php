<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_active_banks(): void
    {
        Bank::factory()->create(['name_ru' => 'Active Bank']);
        Bank::factory()->inactive()->create(['name_ru' => 'Inactive Bank']);

        $this->getJson('/api/banks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name_ru', 'Active Bank')
            ->assertJsonStructure(['data' => [['id', 'name_ru', 'name_tg', 'is_partner']]])
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.status');
    }
}
