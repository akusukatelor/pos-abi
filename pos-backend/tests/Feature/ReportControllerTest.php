<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_summary_separates_sales_purchases_and_verified_service_margin(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $serviceType = ServiceType::create(['name' => 'PPOB', 'is_active' => true]);
        $supplier = Supplier::create(['name' => 'Supplier Uji']);
        $this->createTransaction($cashier, 'cash', '100000.00');
        $this->createTransaction($cashier, 'qris_gopay', '50000.00');
        $this->createPurchase($supplier, $owner, '75000.00');

        ServiceTransaction::create([
            'service_type_id' => $serviceType->id,
            'user_id' => $cashier->id,
            'service_at' => now(),
            'nominal_amount' => '100000.00',
            'margin_amount' => '2500.00',
            'margin_verified_by' => $owner->id,
            'margin_verified_at' => now(),
            'status' => 'completed',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'report-service-verified'),
        ]);

        ServiceTransaction::create([
            'service_type_id' => $serviceType->id,
            'user_id' => $cashier->id,
            'service_at' => now(),
            'nominal_amount' => '50000.00',
            'margin_amount' => null,
            'status' => 'completed',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'report-service-unknown'),
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.sales.transaction_count', 2)
            ->assertJsonPath('data.sales.omzet_barang', '150000.00')
            ->assertJsonPath('data.sales.gross_profit', null)
            ->assertJsonPath('data.purchases.receipt_count', 1)
            ->assertJsonPath('data.purchases.total_pembelian', '75000.00')
            ->assertJsonPath('data.services.verified_margin_count', 1)
            ->assertJsonPath('data.services.verified_margin_total', '2500.00')
            ->assertJsonPath('data.services.margin_not_verified_count', 1);
    }

    public function test_cashier_cannot_access_owner_reports(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/reports/summary')->assertForbidden();
        $this->getJson('/api/reports/sales')->assertForbidden();
        $this->getJson('/api/reports/purchases')->assertForbidden();
        $this->getJson('/api/reports/inventory')->assertForbidden();
        $this->getJson('/api/reports/services')->assertForbidden();
    }

    public function test_sales_report_breaks_down_cash_and_qris_payments(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $this->createTransaction($cashier, 'cash', '30000.00');
        $this->createTransaction($cashier, 'qris_gopay', '70000.00');
        $this->createTransaction($cashier, 'cash', '90000.00', 'unpaid');

        Sanctum::actingAs($owner);
        $this->getJson('/api/reports/sales')
            ->assertOk()
            ->assertJsonPath('summary.transaction_count', 2)
            ->assertJsonPath('summary.omzet_barang', '100000.00')
            ->assertJsonCount(2, 'payment_breakdown');
    }

    public function test_purchase_report_includes_only_confirmed_receipts(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $supplier = Supplier::create(['name' => 'Supplier Laporan']);
        $this->createPurchase($supplier, $owner, '50000.00', 'confirmed');
        $this->createPurchase($supplier, $owner, '90000.00', 'draft');

        Sanctum::actingAs($owner);
        $this->getJson('/api/reports/purchases')
            ->assertOk()
            ->assertJsonPath('summary.receipt_count', 1)
            ->assertJsonPath('summary.total_pembelian', '50000.00');
    }

    public function test_inventory_report_returns_active_and_zero_stock_counts(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $category = Category::create(['name' => 'Makanan']);
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'pcs']);

        Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku_code' => 'RPT-001',
            'name' => 'Produk Ada Stok',
            'buy_price' => 4000,
            'sell_price' => 5000,
            'stock' => 10,
            'is_active' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku_code' => 'RPT-002',
            'name' => 'Produk Habis',
            'buy_price' => 4000,
            'sell_price' => 5000,
            'stock' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/reports/inventory?zero_stock_only=1')
            ->assertOk()
            ->assertJsonPath('summary.active_product_count', 2)
            ->assertJsonPath('summary.zero_stock_product_count', 1)
            ->assertJsonPath('data.total', 1);
    }

    public function test_service_report_excludes_cancelled_records_and_unverified_margin_from_total(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $cashier = User::factory()->create(['role' => 'kasir', 'is_active' => true]);
        $serviceType = ServiceType::create(['name' => 'BRILink', 'is_active' => true]);

        ServiceTransaction::create([
            'service_type_id' => $serviceType->id,
            'user_id' => $cashier->id,
            'service_at' => now(),
            'nominal_amount' => '200000.00',
            'margin_amount' => '3000.00',
            'margin_verified_by' => $owner->id,
            'margin_verified_at' => now(),
            'status' => 'completed',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'service-report-1'),
        ]);
        ServiceTransaction::create([
            'service_type_id' => $serviceType->id,
            'user_id' => $cashier->id,
            'service_at' => now(),
            'nominal_amount' => '100000.00',
            'margin_amount' => null,
            'status' => 'completed',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'service-report-2'),
        ]);
        ServiceTransaction::create([
            'service_type_id' => $serviceType->id,
            'user_id' => $cashier->id,
            'service_at' => now(),
            'nominal_amount' => '50000.00',
            'margin_amount' => '1000.00',
            'margin_verified_by' => $owner->id,
            'margin_verified_at' => now(),
            'status' => 'cancelled',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'service-report-3'),
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/reports/services')
            ->assertOk()
            ->assertJsonPath('summary.completed_count', 2)
            ->assertJsonPath('summary.verified_margin_count', 1)
            ->assertJsonPath('summary.verified_margin_total', '3000.00')
            ->assertJsonPath('summary.margin_not_verified_count', 1);
    }

    private function createTransaction(User $cashier, string $method, string $total, string $status = 'paid'): Transaction
    {
        return Transaction::create([
            'user_id' => $cashier->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(10)),
            'total_price' => $total,
            'pay_amount' => $total,
            'payment_method' => $method,
            'change_amount' => '0.00',
            'discount_amount' => '0.00',
            'payment_status' => $status,
        ]);
    }

    private function createPurchase(Supplier $supplier, User $owner, string $total, string $status = 'confirmed'): PurchaseReceipt
    {
        return PurchaseReceipt::create([
            'receipt_number' => 'RCV-'.Str::upper(Str::random(10)),
            'supplier_id' => $supplier->id,
            'received_at' => now()->toDateString(),
            'status' => $status,
            'payment_status' => $status === 'confirmed' ? 'paid' : null,
            'total_amount' => $total,
            'created_by' => $owner->id,
            'confirmed_by' => $status === 'confirmed' ? $owner->id : null,
            'confirmed_at' => $status === 'confirmed' ? now() : null,
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'receipt-'.$total.'-'.$status.'-'.Str::random(5)),
        ]);
    }
}
