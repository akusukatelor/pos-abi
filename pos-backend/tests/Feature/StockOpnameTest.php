<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockOpnameTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_record_stock_count_but_cannot_approve_adjustment(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $product = $this->createProduct(stock: 10);

        $response = $this->postJson('/api/stock-opnames', $this->opnamePayload($product, 12));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.items.0.system_stock', 10)
            ->assertJsonPath('data.items.0.physical_stock', 12)
            ->assertJsonPath('data.items.0.difference', 2);

        $opnameId = $response->json('data.id');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
        $this->postJson('/api/stock-opnames/'.$opnameId.'/approve')->assertForbidden();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_owner_can_approve_positive_stock_difference(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $product = $this->createProduct(stock: 10);
        $opname = $this->postJson('/api/stock-opnames', $this->opnamePayload($product, 15))
            ->assertCreated()
            ->json('data');

        Sanctum::actingAs($owner);
        $this->postJson('/api/stock-opnames/'.$opname['id'].'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', $owner->id);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 15]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'user_id' => $owner->id,
            'stock_opname_item_id' => $opname['items'][0]['id'],
            'type' => 'in',
            'quantity' => 5,
        ]);
    }

    public function test_owner_can_approve_negative_stock_difference(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);
        $product = $this->createProduct(stock: 10);
        $opname = $this->postJson('/api/stock-opnames', $this->opnamePayload($product, 7))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/stock-opnames/'.$opname['id'].'/approve')->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 7]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 3,
        ]);
    }


    public function test_stock_difference_requires_a_reason(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $product = $this->createProduct(stock: 10);
        $payload = $this->opnamePayload($product, 12);
        $payload['notes'] = null;
        $payload['items'][0]['notes'] = null;

        $this->postJson('/api/stock-opnames', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('stock_opnames', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
    }

    public function test_stale_stock_count_cannot_be_approved(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);
        $product = $this->createProduct(stock: 10);
        $opname = $this->postJson('/api/stock-opnames', $this->opnamePayload($product, 12))
            ->assertCreated()
            ->json('data');

        // Simulate a sale or receipt after the physical count was recorded.
        $product->update(['stock' => 11]);

        $this->postJson('/api/stock-opnames/'.$opname['id'].'/approve')->assertStatus(409);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 11]);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('stock_opnames', ['id' => $opname['id'], 'status' => 'pending_approval']);
    }

    public function test_rejecting_stock_opname_keeps_stock_unchanged(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);
        $product = $this->createProduct(stock: 10);
        $opname = $this->postJson('/api/stock-opnames', $this->opnamePayload($product, 8))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/stock-opnames/'.$opname['id'].'/reject', [
            'rejection_reason' => 'Mohon hitung ulang karena data belum sesuai.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_repeated_stock_opname_approval_does_not_adjust_stock_twice(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);
        $product = $this->createProduct(stock: 10);
        $opname = $this->postJson('/api/stock-opnames', $this->opnamePayload($product, 14))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/stock-opnames/'.$opname['id'].'/approve')->assertOk();
        $this->postJson('/api/stock-opnames/'.$opname['id'].'/approve')
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 14]);
        $this->assertDatabaseCount('stock_movements', 1);
    }


    public function test_matching_physical_count_can_be_approved_without_creating_stock_movement(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);
        $product = $this->createProduct(stock: 10);
        $opname = $this->postJson('/api/stock-opnames', $this->opnamePayload($product, 10))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/stock-opnames/'.$opname['id'].'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_same_idempotency_key_does_not_create_duplicate_stock_opname(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $product = $this->createProduct(stock: 10);
        $payload = $this->opnamePayload($product, 10);

        $this->postJson('/api/stock-opnames', $payload)->assertCreated();
        $this->postJson('/api/stock-opnames', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseCount('stock_opnames', 1);
        $this->assertDatabaseCount('stock_opname_items', 1);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
    }

    private function opnamePayload(Product $product, int $physicalStock): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'counted_at' => '2026-11-03 10:00:00',
            'notes' => 'Penghitungan stok rak utama.',
            'items' => [
                [
                    'product_id' => $product->id,
                    'physical_stock' => $physicalStock,
                    'notes' => 'Dihitung langsung.',
                ],
            ],
        ];
    }

    private function createProduct(int $stock): Product
    {
        $category = Category::create(['name' => 'Makanan']);
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'pcs']);

        return Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku_code' => 'SKU-'.Str::upper(Str::random(8)),
            'name' => 'Produk Uji',
            'buy_price' => '1500.00',
            'sell_price' => '2500.00',
            'stock' => $stock,
            'is_active' => true,
        ]);
    }
}
