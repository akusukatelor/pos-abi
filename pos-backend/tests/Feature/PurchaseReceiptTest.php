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

class PurchaseReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_record_and_confirm_supplier_receipt(): void
    {
        $this->assertRoleCanReceive('kasir');
    }

    public function test_owner_can_record_and_confirm_supplier_receipt(): void
    {
        $this->assertRoleCanReceive('admin');
    }

    public function test_receipt_confirmation_adds_stock_only_once(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $supplier = $this->createSupplier();
        $product = $this->createProduct(stock: 10);
        $payload = $this->receiptPayload($supplier, $product);

        $receipt = $this->postJson('/api/purchase-receipts', $payload)
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/purchase-receipts/'.$receipt['id'].'/confirm', ['received_and_paid_confirmed' => true])->assertOk();
        $this->postJson('/api/purchase-receipts/'.$receipt['id'].'/confirm', ['received_and_paid_confirmed' => true])
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 15]);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_same_idempotency_key_does_not_create_duplicate_receipt(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $supplier = $this->createSupplier();
        $product = $this->createProduct(stock: 10);
        $payload = $this->receiptPayload($supplier, $product);

        $this->postJson('/api/purchase-receipts', $payload)->assertCreated();
        $this->postJson('/api/purchase-receipts', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('purchase_receipt_items', 1);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
    }

    public function test_reusing_receipt_idempotency_key_with_different_payload_returns_conflict(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $supplier = $this->createSupplier();
        $product = $this->createProduct(stock: 10);
        $payload = $this->receiptPayload($supplier, $product);

        $this->postJson('/api/purchase-receipts', $payload)->assertCreated();
        $payload['items'][0]['quantity'] = 99;

        $this->postJson('/api/purchase-receipts', $payload)->assertStatus(409);
        $this->assertDatabaseCount('purchase_receipts', 1);
    }


    public function test_receipt_confirmation_requires_acknowledgement_of_received_and_paid_goods(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);
        $supplier = $this->createSupplier();
        $product = $this->createProduct(stock: 10);
        $receipt = $this->postJson('/api/purchase-receipts', $this->receiptPayload($supplier, $product))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/purchase-receipts/'.$receipt['id'].'/confirm')->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
        $this->assertDatabaseHas('purchase_receipts', ['id' => $receipt['id'], 'status' => 'draft']);
    }

    public function test_confirmed_receipt_cannot_be_deleted(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);
        $supplier = $this->createSupplier();
        $product = $this->createProduct(stock: 10);
        $receipt = $this->postJson('/api/purchase-receipts', $this->receiptPayload($supplier, $product))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/purchase-receipts/'.$receipt['id'].'/confirm', ['received_and_paid_confirmed' => true])->assertOk();
        $this->deleteJson('/api/purchase-receipts/'.$receipt['id'])->assertStatus(409);

        $this->assertDatabaseHas('purchase_receipts', [
            'id' => $receipt['id'],
            'status' => 'confirmed',
        ]);
    }

    public function test_purchase_receipt_requires_authentication(): void
    {
        $this->getJson('/api/purchase-receipts')->assertUnauthorized();
    }

    public function test_supplier_with_purchase_history_cannot_be_deleted(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);
        $supplier = $this->createSupplier();
        $product = $this->createProduct(stock: 10);
        $receipt = $this->postJson('/api/purchase-receipts', $this->receiptPayload($supplier, $product))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/purchase-receipts/'.$receipt['id'].'/confirm', ['received_and_paid_confirmed' => true])->assertOk();
        $this->deleteJson('/api/suppliers/'.$supplier->id)->assertStatus(409);

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    private function assertRoleCanReceive(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);
        $supplier = $this->createSupplier();
        $product = $this->createProduct(stock: 10);

        $response = $this->postJson('/api/purchase-receipts', $this->receiptPayload($supplier, $product));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.payment_status', null)
            ->assertJsonPath('data.total_amount', '8000.00');

        $receiptId = $response->json('data.id');
        $itemId = $response->json('data.items.0.id');

        // A draft is not yet part of available stock.
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);

        $this->postJson('/api/purchase-receipts/'.$receiptId.'/confirm', ['received_and_paid_confirmed' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.confirmed_by', $user->id);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 15]);
        $this->assertDatabaseHas('purchase_receipts', [
            'id' => $receiptId,
            'confirmed_by' => $user->id,
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
            'purchase_receipt_item_id' => $itemId,
            'type' => 'in',
            'quantity' => 5,
        ]);
    }

    private function receiptPayload(Supplier $supplier, Product $product): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'supplier_id' => $supplier->id,
            'received_at' => '2026-11-03',
            'supplier_invoice_reference' => 'NOTA-UJI-001',
            'notes' => 'Barang diterima lengkap.',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'unit_cost' => 1600,
                ],
            ],
        ];
    }

    private function createSupplier(): Supplier
    {
        return Supplier::create(['name' => 'Supplier Uji']);
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
