<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerDebtPayment;
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

class KasbonReportingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_date_and_collection_date_are_separate_and_down_payment_is_not_double_counted(): void
    {
        $this->travelTo('2026-11-24 12:00:00');
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Uji Kasbon', 'total_debt' => '50000.00']);
        $cash = $this->sale($owner, 'cash', '50000.00', '50000.00', 'paid', '2026-11-20 12:00:00');
        $debt = $this->sale($owner, 'debt', '100000.00', '50000.00', 'unpaid', '2026-11-20 12:00:00', $customer->id);
        // DP awal Rp20.000 + cicilan tanggal 24 Rp30.000 = pay_amount sekarang Rp50.000.
        $payment = CustomerDebtPayment::create([
            'customer_id' => $customer->id, 'user_id' => $owner->id,
            'amount' => '30000.00', 'payment_method' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'test-collection'),
        ]);
        $payment->allocations()->create(['transaction_id' => $debt->id, 'amount' => '30000.00']);

        Sanctum::actingAs($owner);
        $this->getJson('/api/reports/summary?from=2026-11-20&to=2026-11-20')
            ->assertOk()
            ->assertJsonPath('data.sales.transaction_count', 2)
            ->assertJsonPath('data.sales.omzet_barang', '150000.00')
            ->assertJsonPath('data.sales.kasbon_sales', '100000.00')
            ->assertJsonPath('data.sales.kasbon_down_payments', '20000.00')
            ->assertJsonPath('data.sales.kasbon_collections', '0.00')
            ->assertJsonPath('data.sales.cash_received', '70000.00');

        $this->getJson('/api/reports/summary?from=2026-11-24&to=2026-11-24')
            ->assertOk()
            ->assertJsonPath('data.sales.omzet_barang', '0.00')
            ->assertJsonPath('data.sales.kasbon_collections', '30000.00')
            ->assertJsonPath('data.sales.cash_received', '30000.00');

        $this->getJson('/api/dashboard/owner?from=2026-11-20&to=2026-11-20')
            ->assertOk()
            ->assertJsonPath('data.sales.omzet_barang', '150000.00')
            ->assertJsonPath('data.sales.cash_received', '70000.00')
            ->assertJsonPath('data.sales.payment_breakdown.0.payment_method', 'cash')
            ->assertJsonPath('data.sales.payment_breakdown.1.payment_method', 'debt');
    }

    public function test_fully_repaid_debt_remains_in_original_sales_period_without_duplicate_sales(): void
    {
        $this->travelTo('2026-11-24 12:00:00');
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Pelunasan', 'total_debt' => '0.00']);
        $debt = $this->sale($owner, 'debt', '100000.00', '100000.00', 'paid', '2026-11-20 12:00:00', $customer->id);
        $payment = CustomerDebtPayment::create([
            'customer_id' => $customer->id, 'user_id' => $owner->id,
            'amount' => '100000.00', 'payment_method' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'full-payment'),
        ]);
        $payment->allocations()->create(['transaction_id' => $debt->id, 'amount' => '100000.00']);

        Sanctum::actingAs($owner);
        $this->getJson('/api/reports/sales?from=2026-11-20&to=2026-11-20')
            ->assertOk()
            ->assertJsonPath('summary.omzet_barang', '100000.00')
            ->assertJsonPath('summary.kasbon_down_payments', '0.00')
            ->assertJsonPath('summary.cash_received', '0.00')
            ->assertJsonPath('payment_breakdown.0.payment_method', 'debt');
        $this->getJson('/api/reports/summary?from=2026-11-24&to=2026-11-24')
            ->assertJsonPath('data.sales.cash_received', '100000.00');
    }

    public function test_debt_units_are_included_in_restock_and_repayment_does_not_increase_units_sold(): void
    {
        $this->travelTo('2026-11-24 12:00:00');
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::create(['name' => 'Minuman P2']);
        $unit = Unit::create(['name' => 'Botol', 'short_name' => 'btl']);
        $product = Product::create([
            'category_id' => $category->id, 'unit_id' => $unit->id,
            'sku_code' => 'P2-RESTOCK', 'name' => 'Air Botol P2',
            'buy_price' => 3000, 'sell_price' => 5000,
            'stock' => 8, 'is_active' => true,
        ]);
        ProductRestockSetting::create([
            'product_id' => $product->id, 'sales_period_days' => 30,
            'lead_time_days' => 3, 'safety_stock' => 4, 'review_interval_days' => 7,
            'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);
        $paid = $this->sale($owner, 'cash', '200000.00', '200000.00', 'paid', '2026-11-22 11:00:00');
        $debt = $this->sale($owner, 'debt', '100000.00', '0.00', 'unpaid', '2026-11-23 11:00:00');
        $excluded = $this->sale($owner, 'cash', '500000.00', '0.00', 'unpaid', '2026-11-23 11:00:00');
        $this->saleItem($paid, $product, 40);
        $this->saleItem($debt, $product, 20);
        $this->saleItem($excluded, $product, 100);

        Sanctum::actingAs($owner);
        $this->getJson('/api/restock-recommendations')
            ->assertOk()
            ->assertJsonPath('data.data.0.sales_basis.units_sold', 60)
            ->assertJsonPath('data.data.0.sales_basis.transaction_count', 2)
            ->assertJsonPath('data.data.0.sales_basis.included_status', 'paid_or_debt')
            ->assertJsonPath('data.data.0.recommendation.suggested_order_quantity', 16);
    }

    private function sale(User $user, string $method, string $total, string $paid, string $status, string $date, ?int $customerId = null): Transaction
    {
        $sale = Transaction::create([
            'user_id' => $user->id, 'customer_id' => $customerId,
            'invoice_number' => 'INV-'.Str::upper(Str::random(12)),
            'total_price' => $total, 'pay_amount' => $paid,
            'payment_method' => $method, 'change_amount' => '0.00',
            'discount_amount' => '0.00', 'payment_status' => $status,
        ]);
        Transaction::query()->whereKey($sale->id)->update(['created_at' => $date, 'updated_at' => $date]);
        return $sale;
    }

    private function saleItem(Transaction $sale, Product $product, int $quantity): void
    {
        TransactionDetail::create([
            'transaction_id' => $sale->id, 'product_id' => $product->id,
            'quantity' => $quantity, 'price' => '5000.00',
            'subtotal' => number_format($quantity * 5000, 2, '.', ''),
        ]);
    }
}
