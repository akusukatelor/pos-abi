<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerEcommerceTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock = 10, string $price = '6000.00'): Product
    {
        $category = Category::create(['name' => 'Makanan']);
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        return Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Beras Premium',
            'sku_code' => 'EC-'.Str::upper(Str::random(10)),
            'buy_price' => '4500.00',
            'sell_price' => $price,
            'stock' => $stock,
            'is_active' => true,
        ]);
    }

    private function customer(string $email = 'belanja@example.test'): CustomerAccount
    {
        $customer = Customer::create(['name' => 'Budi', 'phone' => '0812345', 'total_debt' => 0]);
        return CustomerAccount::create([
            'customer_id' => $customer->id,
            'email' => $email,
            'password' => 'Password123!',
            'is_active' => true,
        ]);
    }

    private function payload(Product $product, ?string $uuid = null): array
    {
        return [
            'idempotency_key' => $uuid ?? (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ];
    }

    public function test_guest_sees_only_public_catalog_fields(): void
    {
        $product = $this->product();
        $this->getJson('/api/store/catalog')->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.available', true)
            ->assertJsonMissingPath('data.0.stock')
            ->assertJsonMissingPath('data.0.buy_price')
            ->assertJsonMissingPath('data.0.minimum_stock');
    }

    public function test_customer_can_register_and_password_is_hashed(): void
    {
        $response = $this->postJson('/api/customer/register', [
            'name' => 'Ani', 'email' => 'ANI@example.test', 'phone' => '0812',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        ])->assertCreated()->assertJsonPath('data.account.email', 'ani@example.test');

        $this->assertNotEmpty($response->json('data.access_token'));
        $account = CustomerAccount::firstOrFail();
        $this->assertTrue(Hash::check('Password123!', $account->password));
        $this->assertDatabaseHas('customers', ['id' => $account->customer_id, 'name' => 'Ani']);
    }

    public function test_customer_cannot_access_staff_routes_even_with_valid_token(): void
    {
        Sanctum::actingAs($this->customer(), ['customer']);
        $this->getJson('/api/me')->assertForbidden();
        $this->getJson('/api/customers')->assertForbidden();
        $this->getJson('/api/reports/summary')->assertForbidden();
    }

    public function test_staff_cannot_access_customer_account_routes(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);
        $this->getJson('/api/customer/me')->assertForbidden();
        $this->postJson('/api/customer/orders', [])->assertForbidden();
    }

    public function test_anonymous_checkout_is_rejected(): void
    {
        $this->postJson('/api/customer/orders', [])->assertUnauthorized();
    }

    public function test_customer_creates_pending_order_without_reducing_stock_or_receiving_points(): void
    {
        $product = $this->product(stock: 10);
        $account = $this->customer();
        Sanctum::actingAs($account, ['customer']);
        $this->postJson('/api/customer/orders', $this->payload($product))
            ->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.total_amount', '12000.00');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('loyalty_point_transactions', 0);
    }

    public function test_same_idempotency_key_cannot_create_two_orders(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->customer(), ['customer']);
        $payload = $this->payload($product);
        $this->postJson('/api/customer/orders', $payload)->assertCreated();
        $this->postJson('/api/customer/orders', $payload)
            ->assertOk()->assertJsonPath('meta.idempotent_replay', true);
        $this->assertDatabaseCount('online_orders', 1);
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->customer('a@example.test'), ['customer']);
        $id = $this->postJson('/api/customer/orders', $this->payload($product))->json('data.id');
        Sanctum::actingAs($this->customer('b@example.test'), ['customer']);
        $this->getJson("/api/customer/orders/{$id}")->assertNotFound();
    }

    public function test_owner_complete_order_creates_one_paid_transaction_and_stock_movement(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->customer(), ['customer']);
        $id = $this->postJson('/api/customer/orders', $this->payload($product))->json('data.id');
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/admin/online-orders/{$id}/complete", [
            'cash_received_confirmed' => true,
            'goods_handed_over_confirmed' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment_status', 'paid');
        $this->postJson("/api/admin/online-orders/{$id}/complete", [
            'cash_received_confirmed' => true,
            'goods_handed_over_confirmed' => true,
        ])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 8]);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('transaction_details', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('loyalty_point_transactions', 0);
    }

    public function test_cancel_pending_order_does_not_affect_stock_or_sales(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->customer(), ['customer']);
        $id = $this->postJson('/api/customer/orders', $this->payload($product))->json('data.id');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $this->postJson("/api/admin/online-orders/{$id}/cancel", ['reason' => 'Produk sudah tidak tersedia'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/admin/online-orders/{$id}/complete", [
            'cash_received_confirmed' => true,
            'goods_handed_over_confirmed' => true,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_owner_must_have_enough_stock_when_completing_order(): void
    {
        $product = $this->product(stock: 2);
        Sanctum::actingAs($this->customer(), ['customer']);
        $id = $this->postJson('/api/customer/orders', $this->payload($product))->json('data.id');
        $product->update(['stock' => 0]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $this->postJson("/api/admin/online-orders/{$id}/complete", [
            'cash_received_confirmed' => true,
            'goods_handed_over_confirmed' => true,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('online_orders', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_cashier_cannot_complete_online_order(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'kasir', 'is_active' => true]));
        $this->postJson('/api/admin/online-orders/1/complete', [])->assertForbidden();
    }
}
