<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Bank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBankIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_banks_are_ordered_by_sort_coefficient_then_name_and_not_paginated(): void
    {
        $admin = new AdminUser;
        $admin->forceFill([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'secret-pass-123',
            'role' => 'admin',
            'is_active' => true,
            'api_token' => 'test-admin-token',
        ])->save();

        $b = Bank::factory()->create(['name_ru' => 'Б банк', 'sort_coefficient' => 0]);
        $a = Bank::factory()->create(['name_ru' => 'А банк', 'sort_coefficient' => 0]);
        $priority = Bank::factory()->create(['name_ru' => 'Я банк', 'sort_coefficient' => 10]);
        Bank::factory()->count(25)->create(['sort_coefficient' => 0]);

        $data = $this->withToken('test-admin-token')
            ->getJson('/api/admin/banks')
            ->assertOk()
            ->json('data');

        $this->assertCount(28, $data);
        $this->assertSame((int) $priority->id, $data[0]['id']);
        $this->assertLessThan(
            array_search((int) $b->id, array_column($data, 'id'), true),
            array_search((int) $a->id, array_column($data, 'id'), true),
            'equal coefficient falls back to name order',
        );
    }
}
