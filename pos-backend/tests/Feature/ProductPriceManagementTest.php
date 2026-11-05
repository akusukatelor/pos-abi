<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductPriceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_receiving_catalog_includes_master_price_but_regular_catalog_hides_it(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $product = $this->createProduct();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonMissingPath('data.data.0.buy_price');

        $this->getJson('/api/products?for_receiving=1')
            ->assertOk()
            ->assertJsonPath('data.data.0.buy_price', '1500.00');
    }

    public function test_cashier_cannot_change_master_purchase_price(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $product = $this->createProduct();
        Sanctum::actingAs($cashier);

        $this->patchJson('/api/products/'.$product->id, [
            'buy_price' => 1750,
            'buy_price_change_reason' => 'Harga supplier berubah.',
        ])->assertForbidden();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'buy_price' => '1500.00',
            'stock' => 10,
        ]);
        $this->assertDatabaseCount('product_price_histories', 0);
    }

    public function test_owner_must_provide_reason_and_price_change_is_audited(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->createProduct();
        Sanctum::actingAs($owner);

        $this->patchJson('/api/products/'.$product->id, [
            'buy_price' => 1750,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('product_price_histories', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'buy_price' => '1500.00']);

        $this->patchJson('/api/products/'.$product->id, [
            'buy_price' => 1750,
            'buy_price_change_reason' => 'Supplier menaikkan harga pada nota terbaru.',
        ])->assertOk()
            ->assertJsonPath('data.buy_price', '1750.00');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'buy_price' => '1750.00',
            'stock' => 10,
        ]);
        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $product->id,
            'changed_by' => $owner->id,
            'old_price' => '1500.00',
            'new_price' => '1750.00',
            'reason' => 'Supplier menaikkan harga pada nota terbaru.',
        ]);
    }

    public function test_only_owner_can_view_master_price_history(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $product = $this->createProduct();

        Sanctum::actingAs($owner);
        $this->patchJson('/api/products/'.$product->id, [
            'buy_price' => 1600,
            'buy_price_change_reason' => 'Penyesuaian harga supplier.',
        ])->assertOk();
        $this->getJson('/api/products/'.$product->id.'/price-history')
            ->assertOk()
            ->assertJsonPath('data.data.0.reason', 'Penyesuaian harga supplier.');

        Sanctum::actingAs($cashier);
        $this->getJson('/api/products/'.$product->id.'/price-history')->assertForbidden();
    }

    public function test_actual_receipt_cost_does_not_overwrite_master_purchase_price(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $supplier = Supplier::create(['name' => 'Supplier Uji Harga']);
        $product = $this->createProduct();
        Sanctum::actingAs($cashier);

        $receipt = $this->postJson('/api/purchase-receipts', [
            'idempotency_key' => (string) Str::uuid(),
            'supplier_id' => $supplier->id,
            'received_at' => '2026-11-04',
            'supplier_invoice_reference' => 'NOTA-HARGA-001',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_cost' => 1650,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson('/api/purchase-receipts/'.$receipt['id'].'/confirm', [
            'received_and_paid_confirmed' => true,
        ])->assertOk();

        $this->assertDatabaseHas('purchase_receipt_items', [
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_cost' => '1650.00',
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'buy_price' => '1500.00',
            'stock' => 13,
        ]);
    }

    private function createProduct(): Product
    {
        $category = Category::create(['name' => 'Kategori Uji Harga']);
        $unit = Unit::create(['name' => 'Pieces Uji Harga', 'short_name' => 'pcs']);

        return Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku_code' => 'PRICE-'.Str::upper(Str::random(8)),
            'name' => 'Produk Uji Harga',
            'buy_price' => '1500.00',
            'sell_price' => '2500.00',
            'stock' => 10,
            'is_active' => true,
        ]);
    }
}
