<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductRestockSetting;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RestockRecommendationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_cannot_access_restock_endpoints(): void
    {
        $this->getJson('/api/restock-settings')->assertUnauthorized();
        $this->getJson('/api/restock-recommendations')->assertUnauthorized();
    }

    public function test_owner_can_save_restock_parameters_for_an_active_product(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->createProduct('RESTOCK-001', 'Air Mineral', 8);

        Sanctum::actingAs($owner);

        $this->putJson("/api/restock-settings/{$product->id}", [
            'sales_period_days' => 30,
            'lead_time_days' => 3,
            'safety_stock' => 4,
            'review_interval_days' => 7,
            'manual_minimum_stock' => null,
            'notes' => 'Supplier biasanya mengirim dalam tiga hari.',
        ])
            ->assertOk()
            ->assertJsonPath('data.restock_setting.sales_period_days', 30)
            ->assertJsonPath('data.restock_setting.lead_time_days', 3)
            ->assertJsonPath('data.restock_setting.safety_stock', 4)
            ->assertJsonPath('data.restock_setting.review_interval_days', 7);

        $this->assertDatabaseHas('product_restock_settings', [
            'product_id' => $product->id,
            'sales_period_days' => 30,
            'lead_time_days' => 3,
            'safety_stock' => 4,
            'review_interval_days' => 7,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
    }

    public function test_restock_recommendation_matches_srs_ac11_and_uses_only_paid_sales(): void
    {
        $this->travelTo('2026-11-03 12:00:00');

        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $product = $this->createProduct('RESTOCK-002', 'Air Mineral', 8);

        ProductRestockSetting::create([
            'product_id' => $product->id,
            'sales_period_days' => 30,
            'lead_time_days' => 3,
            'safety_stock' => 4,
            'review_interval_days' => 7,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        $this->createSale($cashier, $product, 60, 'paid', now()->subDays(5));
        $this->createSale($cashier, $product, 600, 'unpaid', now()->subDays(2));
        $this->createSale($cashier, $product, 300, 'paid', now()->subDays(45));

        Sanctum::actingAs($owner);

        $this->getJson('/api/restock-recommendations?status=needs_restock')
            ->assertOk()
            ->assertJsonPath('summary.needs_restock_count', 1)
            ->assertJsonPath('data.data.0.product.id', $product->id)
            ->assertJsonPath('data.data.0.sales_basis.units_sold', 60)
            ->assertJsonPath('data.data.0.sales_basis.transaction_count', 1)
            ->assertJsonPath('data.data.0.recommendation.status', 'needs_restock')
            ->assertJsonPath('data.data.0.recommendation.method', 'sales_velocity')
            ->assertJsonPath('data.data.0.recommendation.average_daily_sales', '2.0000')
            ->assertJsonPath('data.data.0.recommendation.reorder_point', '10.00')
            ->assertJsonPath('data.data.0.recommendation.target_stock', '24.00')
            ->assertJsonPath('data.data.0.recommendation.suggested_order_quantity', 16)
            ->assertJsonPath('meta.decision_support_only', true);
    }

    public function test_new_product_without_sales_or_manual_threshold_is_marked_insufficient_data(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->createProduct('RESTOCK-003', 'Produk Baru', 0);

        ProductRestockSetting::create([
            'product_id' => $product->id,
            'sales_period_days' => 30,
            'lead_time_days' => 3,
            'safety_stock' => 4,
            'review_interval_days' => 7,
            'manual_minimum_stock' => null,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/restock-recommendations?status=insufficient_data')
            ->assertOk()
            ->assertJsonPath('summary.insufficient_data_count', 1)
            ->assertJsonPath('data.data.0.recommendation.status', 'insufficient_data')
            ->assertJsonPath('data.data.0.recommendation.reason_code', 'no_sales_history')
            ->assertJsonPath('data.data.0.recommendation.suggested_order_quantity', null);
    }

    public function test_owner_manual_minimum_stock_can_be_used_when_sales_history_is_unavailable(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->createProduct('RESTOCK-004', 'Produk Tanpa Histori', 3);

        Sanctum::actingAs($owner);

        $this->putJson("/api/restock-settings/{$product->id}", [
            'sales_period_days' => 30,
            'lead_time_days' => null,
            'safety_stock' => null,
            'review_interval_days' => null,
            'manual_minimum_stock' => 10,
        ])->assertOk();

        $this->getJson('/api/restock-recommendations')
            ->assertOk()
            ->assertJsonPath('data.data.0.recommendation.status', 'needs_restock')
            ->assertJsonPath('data.data.0.recommendation.method', 'manual_minimum_stock')
            ->assertJsonPath('data.data.0.recommendation.suggested_order_quantity', 7);
    }

    public function test_restock_settings_reject_partial_formula_parameters(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = $this->createProduct('RESTOCK-005', 'Produk Parameter Parsial', 5);

        Sanctum::actingAs($owner);

        $this->putJson("/api/restock-settings/{$product->id}", [
            'sales_period_days' => 30,
            'lead_time_days' => 3,
            'safety_stock' => null,
            'review_interval_days' => 7,
            'manual_minimum_stock' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parameters');

        $this->assertDatabaseMissing('product_restock_settings', [
            'product_id' => $product->id,
        ]);
    }

    public function test_cashier_cannot_access_owner_restock_configuration_or_recommendations(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $product = $this->createProduct('RESTOCK-006', 'Produk Kasir', 5);

        Sanctum::actingAs($cashier);

        $this->getJson('/api/restock-settings')->assertForbidden();
        $this->putJson("/api/restock-settings/{$product->id}", [
            'sales_period_days' => 30,
            'lead_time_days' => 3,
            'safety_stock' => 4,
            'review_interval_days' => 7,
        ])->assertForbidden();
        $this->getJson('/api/restock-recommendations')->assertForbidden();
    }

    public function test_recommendation_marks_product_without_unit_as_insufficient_data(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::create(['name' => 'Kategori Tanpa Satuan']);
        $product = Product::create([
            'category_id' => $category->id,
            'unit_id' => null,
            'sku_code' => 'RESTOCK-007',
            'name' => 'Produk Tanpa Satuan',
            'buy_price' => 1000,
            'sell_price' => 1500,
            'stock' => 0,
            'is_active' => true,
        ]);

        ProductRestockSetting::create([
            'product_id' => $product->id,
            'sales_period_days' => 30,
            'lead_time_days' => 3,
            'safety_stock' => 4,
            'review_interval_days' => 7,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/restock-recommendations')
            ->assertOk()
            ->assertJsonPath('data.data.0.recommendation.status', 'insufficient_data')
            ->assertJsonPath('data.data.0.recommendation.reason_code', 'missing_product_unit');
    }

    private function createProduct(string $sku, string $name, int $stock): Product
    {
        $category = Category::create(['name' => 'Kategori '.$sku]);
        $unit = Unit::create(['name' => 'Botol', 'short_name' => 'btl']);

        return Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku_code' => $sku,
            'name' => $name,
            'buy_price' => 3000,
            'sell_price' => 5000,
            'stock' => $stock,
            'is_active' => true,
        ]);
    }

    private function createSale(
        User $cashier,
        Product $product,
        int $quantity,
        string $paymentStatus,
        \DateTimeInterface $createdAt,
    ): Transaction {
        $transaction = Transaction::create([
            'user_id' => $cashier->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(12)),
            'total_price' => number_format($quantity * 5000, 2, '.', ''),
            'pay_amount' => number_format($quantity * 5000, 2, '.', ''),
            'payment_method' => 'cash',
            'change_amount' => '0.00',
            'discount_amount' => '0.00',
            'payment_status' => $paymentStatus,
        ]);

        Transaction::query()->whereKey($transaction->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        TransactionDetail::create([
            'transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => '5000.00',
            'subtotal' => number_format($quantity * 5000, 2, '.', ''),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $transaction;
    }
}
