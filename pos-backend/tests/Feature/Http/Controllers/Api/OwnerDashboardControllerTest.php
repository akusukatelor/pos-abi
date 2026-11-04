<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_cannot_access_owner_dashboard(): void
    {
        $this->getJson('/api/dashboard/owner')->assertUnauthorized();
    }

    public function test_owner_dashboard_summarizes_paid_sales_confirmed_purchases_and_verified_service_margin(): void
    {
        $this->travelTo('2026-11-03 12:00:00');

        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $category = Category::create(['name' => 'Minuman Dashboard']);
        $unit = Unit::create(['name' => 'Botol', 'short_name' => 'btl']);
        $product = Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku_code' => 'DASH-001',
            'name' => 'Air Mineral Dashboard',
            'buy_price' => 3000,
            'sell_price' => 5000,
            'stock' => 8,
            'is_active' => true,
        ]);

        $this->createSale($cashier, $product, 'cash', 2, 'paid');
        $this->createSale($cashier, $product, 'qris_gopay', 1, 'paid');
        $this->createSale($cashier, $product, 'cash', 10, 'unpaid');

        $supplier = Supplier::create(['name' => 'Supplier Dashboard']);
        $this->createPurchase($supplier, $owner, '50000.00', 'confirmed');
        $this->createPurchase($supplier, $owner, '90000.00', 'draft');

        $serviceType = ServiceType::create(['name' => 'PPOB Dashboard', 'is_active' => true]);
        $this->createService($serviceType, $cashier, $owner, '2500.00', 'completed');
        $this->createService($serviceType, $cashier, null, null, 'completed');
        $this->createService($serviceType, $cashier, $owner, '1000.00', 'cancelled');

        Sanctum::actingAs($owner);

        $this->getJson('/api/dashboard/owner?from=2026-11-01&to=2026-11-03')
            ->assertOk()
            ->assertJsonPath('period.from', '2026-11-01')
            ->assertJsonPath('period.to', '2026-11-03')
            ->assertJsonPath('data.sales.transaction_count', 2)
            ->assertJsonPath('data.sales.omzet_barang', '15000.00')
            ->assertJsonPath('data.sales.gross_profit', null)
            ->assertJsonCount(2, 'data.sales.payment_breakdown')
            ->assertJsonPath('data.purchases.receipt_count', 1)
            ->assertJsonPath('data.purchases.total_pembelian', '50000.00')
            ->assertJsonPath('data.services.completed_count', 2)
            ->assertJsonPath('data.services.verified_margin_count', 1)
            ->assertJsonPath('data.services.verified_margin_total', '2500.00')
            ->assertJsonPath('data.services.margin_not_verified_count', 1)
            ->assertJsonPath('data.inventory.active_product_count', 1)
            ->assertJsonPath('data.top_products.0.product_id', $product->id)
            ->assertJsonPath('data.top_products.0.units_sold', 3)
            ->assertJsonPath('data.sales_trend.0.transaction_count', 2);
    }

    public function test_cashier_cannot_access_owner_dashboard(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/dashboard/owner')->assertForbidden();
    }

    public function test_owner_dashboard_rejects_end_date_before_start_date(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/dashboard/owner?from=2026-11-10&to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    private function createSale(
        User $cashier,
        Product $product,
        string $paymentMethod,
        int $quantity,
        string $paymentStatus,
    ): Transaction {
        $transaction = Transaction::create([
            'user_id' => $cashier->id,
            'invoice_number' => 'DASH-INV-'.Str::upper(Str::random(10)),
            'total_price' => number_format($quantity * 5000, 2, '.', ''),
            'pay_amount' => number_format($quantity * 5000, 2, '.', ''),
            'payment_method' => $paymentMethod,
            'change_amount' => '0.00',
            'discount_amount' => '0.00',
            'payment_status' => $paymentStatus,
        ]);

        TransactionDetail::create([
            'transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => '5000.00',
            'subtotal' => number_format($quantity * 5000, 2, '.', ''),
        ]);

        return $transaction;
    }

    private function createPurchase(
        Supplier $supplier,
        User $owner,
        string $total,
        string $status,
    ): PurchaseReceipt {
        return PurchaseReceipt::create([
            'receipt_number' => 'DASH-RCV-'.Str::upper(Str::random(10)),
            'supplier_id' => $supplier->id,
            'received_at' => now()->toDateString(),
            'status' => $status,
            'payment_status' => $status === 'confirmed' ? 'paid' : null,
            'total_amount' => $total,
            'created_by' => $owner->id,
            'confirmed_by' => $status === 'confirmed' ? $owner->id : null,
            'confirmed_at' => $status === 'confirmed' ? now() : null,
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'dashboard-'.$total.'-'.$status.'-'.Str::random(8)),
        ]);
    }

    private function createService(
        ServiceType $serviceType,
        User $cashier,
        ?User $verifier,
        ?string $margin,
        string $status,
    ): ServiceTransaction {
        return ServiceTransaction::create([
            'service_type_id' => $serviceType->id,
            'user_id' => $cashier->id,
            'service_at' => now(),
            'nominal_amount' => '100000.00',
            'margin_amount' => $margin,
            'margin_verified_by' => $verifier?->id,
            'margin_verified_at' => $verifier ? now() : null,
            'status' => $status,
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'dashboard-service-'.$status.'-'.$margin.'-'.Str::random(8)),
        ]);
    }
}
