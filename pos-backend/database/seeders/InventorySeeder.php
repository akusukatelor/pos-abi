<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        if (Supplier::query()->count() === 0) {
            $this->call(SupplierSeeder::class);
        }

        $owner = User::query()->whereIn('role', ['admin', 'owner'])->first() ?? User::query()->first();
        $kasir = User::query()->where('role', 'kasir')->first() ?? $owner;

        if (!$owner) {
            $this->command?->warn('User belum tersedia. Jalankan seeder user terlebih dahulu.');
            return;
        }

        $suppliers = Supplier::query()->orderBy('id')->get();
        $products = Product::query()->where('is_active', true)->orderBy('id')->get();

        if ($suppliers->isEmpty() || $products->count() < 12) {
            $this->command?->warn('Pastikan data supplier dan minimal 12 produk aktif sudah terisi sebelum menjalankan InventorySeeder.');
            return;
        }

        $now = CarbonImmutable::now();

        DB::transaction(function () use ($owner, $kasir, $suppliers, $products, $now): void {
            // =================================================================
            // 1. PENERIMAAN BARANG SUPPLIER (4 Confirmed + 1 Draft)
            // =================================================================
            $receiptSpecs = [
                [
                    'receipt_number' => 'PR-DEMO-0001',
                    'supplier' => $suppliers[1 % $suppliers->count()],
                    'received_at' => $now->subDays(12)->toDateString(),
                    'invoice_ref' => 'NOTA-KT-20261114',
                    'status' => 'confirmed',
                    'notes' => 'Restok mingguan beras dan sembako pokok',
                    'created_by' => $kasir->id,
                    'confirmed_by' => $owner->id,
                    'confirmed_at' => $now->subDays(12)->setTime(10, 15),
                    'lines' => [
                        ['product' => $products[0], 'qty' => 15],
                        ['product' => $products[1], 'qty' => 20],
                        ['product' => $products[2], 'qty' => 12],
                    ],
                ],
                [
                    'receipt_number' => 'PR-DEMO-0002',
                    'supplier' => $suppliers[3 % $suppliers->count()],
                    'received_at' => $now->subDays(8)->toDateString(),
                    'invoice_ref' => 'INV-IAP-88412',
                    'status' => 'confirmed',
                    'notes' => 'Pengiriman rutin produk Indofood & mie instan',
                    'created_by' => $owner->id,
                    'confirmed_by' => $owner->id,
                    'confirmed_at' => $now->subDays(8)->setTime(14, 30),
                    'lines' => [
                        ['product' => $products[3], 'qty' => 40],
                        ['product' => $products[4], 'qty' => 30],
                        ['product' => $products[5], 'qty' => 24],
                    ],
                ],
                [
                    'receipt_number' => 'PR-DEMO-0003',
                    'supplier' => $suppliers[6 % $suppliers->count()],
                    'received_at' => $now->subDays(4)->toDateString(),
                    'invoice_ref' => 'DO-PWT-55109',
                    'status' => 'confirmed',
                    'notes' => 'Restok air mineral dan minuman kemasan',
                    'created_by' => $kasir->id,
                    'confirmed_by' => $owner->id,
                    'confirmed_at' => $now->subDays(4)->setTime(11, 20),
                    'lines' => [
                        ['product' => $products[6], 'qty' => 24],
                        ['product' => $products[7], 'qty' => 24],
                    ],
                ],
                [
                    'receipt_number' => 'PR-DEMO-0004',
                    'supplier' => $suppliers[4 % $suppliers->count()],
                    'received_at' => $now->subDays(2)->toDateString(),
                    'invoice_ref' => 'SJ-WGS-3091',
                    'status' => 'confirmed',
                    'notes' => 'Sabun cuci dan perlengkapan rumah tangga',
                    'created_by' => $owner->id,
                    'confirmed_by' => $owner->id,
                    'confirmed_at' => $now->subDays(2)->setTime(15, 45),
                    'lines' => [
                        ['product' => $products[8], 'qty' => 18],
                        ['product' => $products[9], 'qty' => 15],
                    ],
                ],
                // Draft: siap dikonfirmasi langsung saat demo live
                [
                    'receipt_number' => 'PR-DEMO-0005',
                    'supplier' => $suppliers[0],
                    'received_at' => $now->toDateString(),
                    'invoice_ref' => 'NOTA-SRC-7721',
                    'status' => 'draft',
                    'notes' => 'Barang baru tiba di toko, menunggu pengecekan fisik & pembayaran nota',
                    'created_by' => $kasir->id,
                    'confirmed_by' => null,
                    'confirmed_at' => null,
                    'lines' => [
                        ['product' => $products[10], 'qty' => 12],
                        ['product' => $products[11], 'qty' => 10],
                    ],
                ],
            ];

            foreach ($receiptSpecs as $spec) {
                $existingReceipt = PurchaseReceipt::query()
                    ->where('receipt_number', $spec['receipt_number'])
                    ->first();

                if ($existingReceipt) {
                    continue;
                }

                $totalCents = 0;
                $preparedLines = [];

                foreach ($spec['lines'] as $line) {
                    /** @var Product $product */
                    $product = $line['product'];
                    $qty = (int) $line['qty'];
                    $unitCostCents = max(100000, (int) round(((float) $product->buy_price) * 100));
                    $lineTotalCents = $unitCostCents * $qty;
                    $totalCents += $lineTotalCents;

                    $preparedLines[] = [
                        'product' => $product,
                        'quantity' => $qty,
                        'unit_cost' => number_format($unitCostCents / 100, 2, '.', ''),
                        'line_total' => number_format($lineTotalCents / 100, 2, '.', ''),
                    ];
                }

                $isConfirmed = $spec['status'] === 'confirmed';

                $receipt = PurchaseReceipt::create([
                    'receipt_number' => $spec['receipt_number'],
                    'supplier_id' => $spec['supplier']->id,
                    'received_at' => $spec['received_at'],
                    'supplier_invoice_reference' => $spec['invoice_ref'],
                    'status' => $spec['status'],
                    'payment_status' => $isConfirmed ? 'paid' : null,
                    'total_amount' => number_format($totalCents / 100, 2, '.', ''),
                    'notes' => $spec['notes'],
                    'created_by' => $spec['created_by'],
                    'confirmed_by' => $spec['confirmed_by'],
                    'confirmed_at' => $spec['confirmed_at'],
                    'idempotency_key' => (string) Str::uuid(),
                    'idempotency_hash' => hash('sha256', 'seed:receipt:'.$spec['receipt_number']),
                ]);

                foreach ($preparedLines as $line) {
                    $receiptItem = $receipt->items()->create([
                        'product_id' => $line['product']->id,
                        'quantity' => $line['quantity'],
                        'unit_cost' => $line['unit_cost'],
                        'line_total' => $line['line_total'],
                    ]);

                    if ($isConfirmed) {
                        /** @var Product $freshProduct */
                        $freshProduct = Product::query()->lockForUpdate()->findOrFail($line['product']->id);
                        $freshProduct->stock += $line['quantity'];
                        $freshProduct->save();

                        StockMovement::create([
                            'product_id' => $freshProduct->id,
                            'user_id' => $spec['confirmed_by'],
                            'supplier_id' => $spec['supplier']->id,
                            'purchase_receipt_item_id' => $receiptItem->id,
                            'type' => 'in',
                            'quantity' => $line['quantity'],
                            'description' => 'Penerimaan supplier '.$receipt->receipt_number,
                        ]);
                    }
                }
            }

            // =================================================================
            // 2. STOCK OPNAME (1 Approved, 1 Rejected, 1 Pending Approval)
            // =================================================================
            $prodApprovedA = Product::query()->findOrFail($products[0]->id);
            $prodApprovedB = Product::query()->findOrFail($products[3]->id);

            // 2a. Approved Stock Opname
            if (!StockOpname::query()->where('opname_number', 'OPN-DEMO-0001')->exists()) {
                $countedAt1 = $now->subDays(6)->setTime(16, 0);
                $sysA = (int) $prodApprovedA->stock;
                $physA = max(0, $sysA - 2);
                $diffA = $physA - $sysA;

                $sysB = (int) $prodApprovedB->stock;
                $physB = $sysB;
                $diffB = 0;

                $opname1 = StockOpname::create([
                    'opname_number' => 'OPN-DEMO-0001',
                    'counted_at' => $countedAt1,
                    'snapshot_at' => $countedAt1,
                    'status' => 'approved',
                    'notes' => 'Stock opname mingguan rak depan',
                    'created_by' => $kasir->id,
                    'approved_by' => $owner->id,
                    'approved_at' => $countedAt1->addMinutes(25),
                    'idempotency_key' => (string) Str::uuid(),
                    'idempotency_hash' => hash('sha256', 'seed:opname:OPN-DEMO-0001'),
                ]);

                $item1A = $opname1->items()->create([
                    'product_id' => $prodApprovedA->id,
                    'system_stock' => $sysA,
                    'physical_stock' => $physA,
                    'difference' => $diffA,
                    'notes' => $diffA !== 0 ? '2 kemasan rusak/penyok saat penataan rak' : 'Sesuai stok fisik',
                ]);

                $opname1->items()->create([
                    'product_id' => $prodApprovedB->id,
                    'system_stock' => $sysB,
                    'physical_stock' => $physB,
                    'difference' => $diffB,
                    'notes' => 'Jumlah fisik sesuai sistem',
                ]);

                if ($diffA !== 0) {
                    $prodApprovedA->stock = $physA;
                    $prodApprovedA->save();

                    StockMovement::create([
                        'product_id' => $prodApprovedA->id,
                        'user_id' => $owner->id,
                        'stock_opname_item_id' => $item1A->id,
                        'type' => $diffA > 0 ? 'in' : 'out',
                        'quantity' => abs($diffA),
                        'description' => 'Penyesuaian stock opname '.$opname1->opname_number,
                    ]);
                }
            }

            // 2b. Rejected Stock Opname
            if (!StockOpname::query()->where('opname_number', 'OPN-DEMO-0002')->exists()) {
                $countedAt2 = $now->subDays(3)->setTime(17, 10);
                $prodRej = Product::query()->findOrFail($products[6]->id);
                $sysRej = (int) $prodRej->stock;
                $physRej = max(0, $sysRej - 5);

                $opname2 = StockOpname::create([
                    'opname_number' => 'OPN-DEMO-0002',
                    'counted_at' => $countedAt2,
                    'snapshot_at' => $countedAt2,
                    'status' => 'rejected',
                    'notes' => 'Hitung cepat stok etalase minuman',
                    'created_by' => $kasir->id,
                    'rejected_by' => $owner->id,
                    'rejected_at' => $countedAt2->addMinutes(15),
                    'rejection_reason' => 'Stok dus cadangan di gudang belakang belum ikut dihitung, silakan hitung ulang.',
                    'idempotency_key' => (string) Str::uuid(),
                    'idempotency_hash' => hash('sha256', 'seed:opname:OPN-DEMO-0002'),
                ]);

                $opname2->items()->create([
                    'product_id' => $prodRej->id,
                    'system_stock' => $sysRej,
                    'physical_stock' => $physRej,
                    'difference' => $physRej - $sysRej,
                    'notes' => 'Hanya menghitung stok di kulkas depan',
                ]);
            }

            // 2c. Pending Approval Stock Opname (pakai produk index 1 & 2 agar tidak bentrok dengan draft PR-DEMO-0005)
            $prodPendA = Product::query()->findOrFail($products[1]->id);
            $prodPendB = Product::query()->findOrFail($products[2]->id);

            $existingPending = StockOpname::query()
                ->where('opname_number', 'OPN-DEMO-0003')
                ->first();

            if (!$existingPending) {
                $countedAt3 = $now->subHour();
                $sysPA = (int) $prodPendA->stock;
                $physPA = max(0, $sysPA - 1);

                $sysPB = (int) $prodPendB->stock;
                $physPB = $sysPB + 2;

                $opname3 = StockOpname::create([
                    'opname_number' => 'OPN-DEMO-0003',
                    'counted_at' => $countedAt3,
                    'snapshot_at' => $countedAt3,
                    'status' => 'pending_approval',
                    'notes' => 'Hasil hitung fisik sore hari oleh kasir',
                    'created_by' => $kasir->id,
                    'idempotency_key' => (string) Str::uuid(),
                    'idempotency_hash' => hash('sha256', 'seed:opname:OPN-DEMO-0003'),
                ]);

                $opname3->items()->create([
                    'product_id' => $prodPendA->id,
                    'system_stock' => $sysPA,
                    'physical_stock' => $physPA,
                    'difference' => $physPA - $sysPA,
                    'notes' => '1 kemasan bocor saat dipindahkan ke etalase',
                ]);

                $opname3->items()->create([
                    'product_id' => $prodPendB->id,
                    'system_stock' => $sysPB,
                    'physical_stock' => $physPB,
                    'difference' => $physPB - $sysPB,
                    'notes' => 'Ditemukan 2 pcs terselip di kardus cadangan',
                ]);
            } elseif ($existingPending->status === 'pending_approval') {
                // Pastikan system_stock tetap sinkron jika seeder dijalankan ulang
                foreach ($existingPending->items as $item) {
                    $currentProduct = Product::query()->find($item->product_id);
                    if ($currentProduct && $item->system_stock !== $currentProduct->stock) {
                        $delta = $item->difference;
                        $newSystem = (int) $currentProduct->stock;
                        $newPhysical = max(0, $newSystem + $delta);
                        $item->update([
                            'system_stock' => $newSystem,
                            'physical_stock' => $newPhysical,
                            'difference' => $newPhysical - $newSystem,
                        ]);
                    }
                }
            }
        }, 3);

        $this->command?->info('Berhasil mengisi data Penerimaan Barang, Stock Opname, dan Riwayat Stok.');
    }
}