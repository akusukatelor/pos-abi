<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_can_store_customer_id_during_checkout(): void
    {
        $user = User::factory()->create();
        $customer = Customer::create([
            'name' => 'Budi',
            'phone' => '08123456789',
            'address' => 'Jakarta',
            'total_debt' => 0,
        ]);

        $category = Category::create(['name' => 'Minuman']);
        $unit = Unit::create(['name' => 'Botol', 'short_name' => 'btl']);

        $product = Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku_code' => 'SKU-TXN-1',
            'name' => 'Es Teh',
            'buy_price' => 3000,
            'sell_price' => 7000,
            'stock' => 10,
            'minimum_stock' => 3,
        ]);

        $response = $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'pay_amount' => 20000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.invoice_number', $response->json('data.invoice_number'));
    }
}
