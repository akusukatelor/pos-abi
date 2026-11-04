<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_checkout_updates_stock_and_creates_stock_ledger_entry(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $product = $this->createProduct(stock: 10, sellPrice: '5000.00');

        $response = $this->postJson('/api/transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'pay_amount' => 12000,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.change_amount', '2000.00');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $cashier->id,
            'total_price' => '10000.00',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'user_id' => $cashier->id,
            'type' => 'out',
            'quantity' => 2,
        ]);
    }

    public function test_underpayment_is_rejected_without_changing_stock(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $product = $this->createProduct(stock: 10, sellPrice: '5000.00');

        $this->postJson('/api/transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'pay_amount' => 9000,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_repeated_checkout_with_same_idempotency_key_does_not_decrement_stock_twice(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $product = $this->createProduct(stock: 10, sellPrice: '5000.00');
        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'pay_amount' => 5000,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $this->postJson('/api/transactions', $payload)->assertCreated();
        $this->postJson('/api/transactions', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 9]);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_qris_checkout_requires_manual_cashier_confirmation(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $product = $this->createProduct(stock: 10, sellPrice: '5000.00');

        $this->postJson('/api/transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'qris_gopay',
            'pay_amount' => 5000,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_qris_checkout_records_cashier_verification_and_updates_stock(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $product = $this->createProduct(stock: 10, sellPrice: '5000.00');

        $response = $this->postJson('/api/transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'qris_gopay',
            'pay_amount' => 5000,
            'manual_verification_confirmed' => true,
            'payment_reference' => 'GOPAY-MERCHANT-REF-001',
            // Client-provided verifier identity must be ignored; server uses authenticated user.
            'qris_verified_by' => 999999,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method', 'qris_gopay')
            ->assertJsonPath('data.pay_amount', '5000.00')
            ->assertJsonPath('data.change_amount', '0.00')
            ->assertJsonPath('data.qris_verified_by', $cashier->id)
            ->assertJsonPath('data.payment_reference', 'GOPAY-MERCHANT-REF-001');

        $this->assertNotNull($response->json('data.qris_verified_at'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 9]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $cashier->id,
            'payment_method' => 'qris_gopay',
            'payment_status' => 'paid',
            'qris_verified_by' => $cashier->id,
            'payment_reference' => 'GOPAY-MERCHANT-REF-001',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'user_id' => $cashier->id,
            'type' => 'out',
            'quantity' => 1,
        ]);
    }

    public function test_repeated_qris_checkout_with_same_idempotency_key_does_not_decrement_stock_twice(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $product = $this->createProduct(stock: 10, sellPrice: '5000.00');
        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'qris_gopay',
            'pay_amount' => 5000,
            'manual_verification_confirmed' => true,
            'payment_reference' => 'GOPAY-MERCHANT-REF-REPLAY',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $this->postJson('/api/transactions', $payload)->assertCreated();
        $this->postJson('/api/transactions', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 9]);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_qris_checkout_requires_exact_payment_amount(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);
        $product = $this->createProduct(stock: 10, sellPrice: '5000.00');

        $this->postJson('/api/transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'qris_gopay',
            'pay_amount' => 6000,
            'manual_verification_confirmed' => true,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_internal_products_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
    }

    public function test_cashier_cannot_access_owner_user_management_endpoint(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/users')->assertForbidden();
    }


    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => bcrypt('password'),
            'role' => 'kasir',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactive@example.com',
            'password' => 'password',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_read_own_profile(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $cashier->id)
            ->assertJsonPath('data.role', 'kasir');
    }

    private function createProduct(int $stock, string $sellPrice): Product
    {
        $category = Category::create(['name' => 'Makanan']);
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'pcs']);

        return Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku_code' => 'SKU-'.Str::upper(Str::random(8)),
            'name' => 'Produk Uji',
            'buy_price' => '4000.00',
            'sell_price' => $sellPrice,
            'stock' => $stock,
            'is_active' => true,
        ]);
    }
}
