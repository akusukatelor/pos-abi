<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmartInventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_low_stock_recommendations(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'kasir', 'is_active' => true]));

        $category = Category::create(['name' => 'Elektronik']);
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku_code' => 'SKU-LOW-1',
            'name' => 'Router Lite',
            'buy_price' => 200000,
            'sell_price' => 300000,
            'stock' => 2,
            'minimum_stock' => 5,
        ]);

        Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku_code' => 'SKU-OK-1',
            'name' => 'Router Stable',
            'buy_price' => 250000,
            'sell_price' => 350000,
            'stock' => 12,
            'minimum_stock' => 5,
        ]);

        $response = $this->getJson('/api/products/restock');

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'Router Lite');
        $response->assertJsonPath('data.0.recommended_quantity', 3);
    }

    public function test_it_returns_ai_assistant_reply_for_stock_questions(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'kasir', 'is_active' => true]));

        $category = Category::create(['name' => 'Perawatan']);
        $unit = Unit::create(['name' => 'Botol', 'short_name' => 'btl']);

        Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku_code' => 'SKU-AI-1',
            'name' => 'Sabun Cuci',
            'buy_price' => 15000,
            'sell_price' => 25000,
            'stock' => 3,
            'minimum_stock' => 10,
        ]);

        $response = $this->postJson('/api/assistant/chat', [
            'question' => 'berapa stok sabun cuci?',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('Stok Sabun Cuci saat ini 3 pcs', $response->json('data.answer'));
    }
}
