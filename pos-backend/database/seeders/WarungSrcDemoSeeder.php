<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WarungSrcDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = CarbonImmutable::now();
        $today = CarbonImmutable::today();

        // 1. Pastikan tersedia akun Owner (admin) dan Kasir untuk relasi transaksi & pengaturan
        $ownerId = DB::table('users')->where('role', 'admin')->value('id');
        if (!$ownerId) {
            $ownerId = DB::table('users')->insertGetId([
                'name' => 'Owner Warung SRC',
                'email' => 'owner@warungsrc.test',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);
        }

        $cashierId = DB::table('users')->where('role', 'kasir')->value('id') ?? $ownerId;

        // 2. Seed Kategori Warung SRC
        $categoryNames = [
            'Sembako',
            'Mie & Makanan Instan',
            'Minuman Kemasan',
            'Kopi, Teh & Susu',
            'Makanan Ringan & Biskuit',
            'Bumbu Dapur & Masak',
            'Perlengkapan Mandi & Cuci',
            'Obat Warung & Kebutuhan Rumah',
        ];

        $categoryIds = [];
        foreach ($categoryNames as $name) {
            $existingId = DB::table('categories')->where('name', $name)->value('id');
            if ($existingId) {
                $categoryIds[$name] = (int) $existingId;
            } else {
                $categoryIds[$name] = (int) DB::table('categories')->insertGetId([
                    'name' => $name,
                    'created_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ]);
            }
        }

        // 3. Seed Satuan Produk
        $unitsData = [
            ['name' => 'Pcs', 'short_name' => 'pcs'],
            ['name' => 'Pack', 'short_name' => 'pack'],
            ['name' => 'Kilogram', 'short_name' => 'kg'],
            ['name' => 'Botol', 'short_name' => 'btl'],
            ['name' => 'Kaleng', 'short_name' => 'klg'],
            ['name' => 'Renteng', 'short_name' => 'rtg'],
            ['name' => 'Box', 'short_name' => 'box'],
            ['name' => 'Tabung', 'short_name' => 'tbg'],
        ];

        $unitIds = [];
        foreach ($unitsData as $unit) {
            $existingId = DB::table('units')->where('short_name', $unit['short_name'])->value('id');
            if ($existingId) {
                $unitIds[$unit['short_name']] = (int) $existingId;
            } else {
                $unitIds[$unit['short_name']] = (int) DB::table('units')->insertGetId([
                    'name' => $unit['name'],
                    'short_name' => $unit['short_name'],
                    'created_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ]);
            }
        }

        // 4. Daftar 50 Produk Warung SRC dengan variasi pola permintaan (Holt Time-Series)
        // Pattern: 'trending_up', 'volatile', 'steady', 'trending_down', 'slow'
        $catalog = [
            // Sembako (8)
            ['SRC-001', 'Beras Ramos Setra 5 kg', 'Sembako', 'pack', 64000, 72000, 4, 3, 'trending_up'],
            ['SRC-002', 'Minyak Goreng Bimoli 1 Liter', 'Sembako', 'pcs', 16500, 19000, 6, 4, 'volatile'],
            ['SRC-003', 'Minyak Goreng SunCo 2 Liter', 'Sembako', 'pcs', 33000, 37500, 12, 4, 'steady'],
            ['SRC-004', 'Gula Pasir Gulaku 1 kg', 'Sembako', 'pack', 15500, 18000, 5, 4, 'trending_up'],
            ['SRC-005', 'Telur Ayam Negeri', 'Sembako', 'kg', 25000, 29000, 4, 5, 'volatile'],
            ['SRC-006', 'Tepung Terigu Segitiga Biru 1 kg', 'Sembako', 'pack', 11000, 13500, 14, 3, 'steady'],
            ['SRC-007', 'Gas Elpiji LPG 3 kg', 'Sembako', 'tbg', 18000, 22000, 3, 5, 'trending_up'],
            ['SRC-008', 'Garam Dapur Refina 250 gr', 'Sembako', 'pcs', 3000, 4500, 18, 3, 'slow'],

            // Mie & Makanan Instan (7)
            ['SRC-009', 'Indomie Goreng Original', 'Mie & Makanan Instan', 'pcs', 2700, 3500, 18, 15, 'trending_up'],
            ['SRC-010', 'Indomie Kuah Soto Mie', 'Mie & Makanan Instan', 'pcs', 2600, 3500, 32, 10, 'steady'],
            ['SRC-011', 'Indomie Kuah Kari Ayam', 'Mie & Makanan Instan', 'pcs', 2700, 3500, 26, 10, 'steady'],
            ['SRC-012', 'Mie Sedaap Goreng', 'Mie & Makanan Instan', 'pcs', 2650, 3500, 10, 10, 'volatile'],
            ['SRC-013', 'Pop Mie Rasa Ayam Bawang', 'Mie & Makanan Instan', 'pcs', 4500, 6000, 5, 6, 'trending_up'],
            ['SRC-014', 'Sardines ABC Tomat 155 gr', 'Mie & Makanan Instan', 'klg', 9500, 12000, 16, 3, 'trending_down'],
            ['SRC-015', 'Kornet Sapi Pronas 198 gr', 'Mie & Makanan Instan', 'klg', 21000, 25000, 8, 2, 'slow'],

            // Minuman Kemasan (8)
            ['SRC-016', 'Aqua Air Mineral 600 ml', 'Minuman Kemasan', 'btl', 2500, 4000, 10, 12, 'trending_up'],
            ['SRC-017', 'Aqua Air Mineral 1500 ml', 'Minuman Kemasan', 'btl', 5000, 7000, 14, 6, 'steady'],
            ['SRC-018', 'Le Minerale 600 ml', 'Minuman Kemasan', 'btl', 2400, 3500, 8, 10, 'volatile'],
            ['SRC-019', 'Teh Pucuk Harum 350 ml', 'Minuman Kemasan', 'btl', 2800, 4000, 7, 10, 'trending_up'],
            ['SRC-020', 'Teh Botol Sosro Kotak 250 ml', 'Minuman Kemasan', 'pcs', 3000, 4500, 22, 6, 'steady'],
            ['SRC-021', 'Floridina Orange 350 ml', 'Minuman Kemasan', 'btl', 2600, 4000, 20, 5, 'trending_down'],
            ['SRC-022', 'Pocari Sweat 500 ml', 'Minuman Kemasan', 'btl', 6200, 8000, 5, 5, 'volatile'],
            ['SRC-023', 'Larutan Penyegar Cap Kaki Tiga 320 ml', 'Minuman Kemasan', 'klg', 5500, 7500, 15, 4, 'steady'],

            // Kopi, Teh & Susu (7)
            ['SRC-024', 'Kopi Kapal Api Special Mix', 'Kopi, Teh & Susu', 'rtg', 13500, 16500, 3, 4, 'trending_up'],
            ['SRC-025', 'Kopi Good Day Mocacinno', 'Kopi, Teh & Susu', 'rtg', 14000, 17000, 9, 3, 'steady'],
            ['SRC-026', 'Kopi ABC Susu', 'Kopi, Teh & Susu', 'rtg', 13000, 16000, 4, 4, 'volatile'],
            ['SRC-027', 'Teh Celup SariWangi Isi 25', 'Kopi, Teh & Susu', 'box', 5800, 7500, 11, 3, 'steady'],
            ['SRC-028', 'Susu Kental Manis Frisian Flag Putih', 'Kopi, Teh & Susu', 'klg', 11000, 13500, 14, 3, 'steady'],
            ['SRC-029', 'Susu UHT Ultra Milk Cokelat 250 ml', 'Kopi, Teh & Susu', 'pcs', 5800, 7500, 6, 8, 'trending_up'],
            ['SRC-030', 'Energen Cokelat', 'Kopi, Teh & Susu', 'rtg', 16000, 19500, 12, 3, 'trending_down'],

            // Makanan Ringan & Biskuit (6)
            ['SRC-031', 'Chitato Sapi Panggang 68 gr', 'Makanan Ringan & Biskuit', 'pcs', 9500, 12000, 4, 4, 'volatile'],
            ['SRC-032', 'Qtela Singkong Barbeque 60 gr', 'Makanan Ringan & Biskuit', 'pcs', 7500, 9500, 15, 3, 'trending_down'],
            ['SRC-033', 'Biskuit Roma Kelapa 300 gr', 'Makanan Ringan & Biskuit', 'pack', 9800, 12500, 10, 3, 'steady'],
            ['SRC-034', 'Wafer Tango Cokelat 110 gr', 'Makanan Ringan & Biskuit', 'pack', 6500, 8500, 14, 3, 'trending_down'],
            ['SRC-035', 'Beng-Beng Wafer Cokelat Isi 20', 'Makanan Ringan & Biskuit', 'box', 31000, 37000, 2, 3, 'trending_up'],
            ['SRC-036', 'Kacang Garuda Kulit 180 gr', 'Makanan Ringan & Biskuit', 'pack', 14500, 18000, 11, 2, 'slow'],

            // Bumbu Dapur & Masak (5)
            ['SRC-037', 'Kecap Manis Bango 210 ml', 'Bumbu Dapur & Masak', 'pcs', 10500, 13000, 9, 3, 'steady'],
            ['SRC-038', 'Saus Sambal ABC 135 ml', 'Bumbu Dapur & Masak', 'btl', 6800, 8500, 13, 3, 'steady'],
            ['SRC-039', 'Penyedap Rasa Royco Ayam', 'Bumbu Dapur & Masak', 'rtg', 4800, 6500, 3, 4, 'trending_up'],
            ['SRC-040', 'Santan Kara Instan 65 ml', 'Bumbu Dapur & Masak', 'pcs', 3200, 4500, 7, 5, 'volatile'],
            ['SRC-041', 'Ladaku Merica Bubuk', 'Bumbu Dapur & Masak', 'rtg', 9500, 12000, 10, 2, 'slow'],

            // Perlengkapan Mandi & Cuci (6)
            ['SRC-042', 'Sabun Mandi Lifebuoy Merah 75 gr', 'Perlengkapan Mandi & Cuci', 'pcs', 3600, 5000, 16, 4, 'steady'],
            ['SRC-043', 'Sampo Sunsilk Black Shine', 'Perlengkapan Mandi & Cuci', 'rtg', 9500, 12000, 4, 3, 'volatile'],
            ['SRC-044', 'Pasta Gigi Pepsodent 120 gr', 'Perlengkapan Mandi & Cuci', 'pcs', 7200, 9500, 12, 3, 'steady'],
            ['SRC-045', 'Deterjen Rinso Anti Noda 770 gr', 'Perlengkapan Mandi & Cuci', 'pack', 18500, 22500, 3, 4, 'trending_up'],
            ['SRC-046', 'Sabun Cuci Piring Sunlight 210 ml', 'Perlengkapan Mandi & Cuci', 'pcs', 4500, 6000, 5, 4, 'volatile'],
            ['SRC-047', 'Pewangi Pakaian Molto Sachet', 'Perlengkapan Mandi & Cuci', 'rtg', 5200, 7000, 14, 3, 'trending_down'],

            // Obat Warung & Kebutuhan Rumah (3)
            ['SRC-048', 'Tolak Angin Cair Sido Muncul', 'Obat Warung & Kebutuhan Rumah', 'box', 39000, 46000, 8, 2, 'trending_down'],
            ['SRC-049', 'Obat Nyamuk Bakar Baygon', 'Obat Warung & Kebutuhan Rumah', 'box', 4200, 5500, 15, 3, 'slow'],
            ['SRC-050', 'Tisu Wajah Paseo Smart 250 Sheets', 'Obat Warung & Kebutuhan Rumah', 'pack', 11500, 14500, 11, 3, 'trending_down'],
        ];

        $seededProducts = [];

        DB::transaction(function () use ($catalog, $categoryIds, $unitIds, $now, &$seededProducts): void {
            foreach ($catalog as $index => [$sku, $name, $catName, $unitShort, $buyPrice, $sellPrice, $stock, $minStock, $pattern]) {
                DB::table('products')->updateOrInsert(
                    ['sku_code' => $sku],
                    [
                        'category_id' => $categoryIds[$catName],
                        'name' => $name,
                        'buy_price' => $buyPrice,
                        'sell_price' => $sellPrice,
                        'stock' => $stock,
                        'is_active' => true,
                        'minimum_stock' => $minStock,
                        'unit_id' => $unitIds[$unitShort],
                        'created_at' => $now->subDays(45)->toDateTimeString(),
                        'updated_at' => $now->toDateTimeString(),
                    ]
                );

                $productId = (int) DB::table('products')->where('sku_code', $sku)->value('id');

                $seededProducts[] = [
                    'id' => $productId,
                    'index' => $index,
                    'sku' => $sku,
                    'name' => $name,
                    'sell_price' => (float) $sellPrice,
                    'pattern' => $pattern,
                ];
            }
        });

        // 5. Tambahkan pengaturan restok khusus untuk produk-produk prioritas Warung SRC
        // Produk lainnya akan otomatis menggunakan parameter default toko (30 hari, lead time 2, safety 2, review 7)
        $customSettings = [
            'SRC-001' => ['period' => 30, 'lead' => 3, 'safety' => 4, 'review' => 7, 'notes' => 'Agen beras kirim setiap Senin & Kamis'],
            'SRC-002' => ['period' => 30, 'lead' => 2, 'safety' => 5, 'review' => 7, 'notes' => 'Permintaan sering melonjak saat akhir pekan'],
            'SRC-005' => ['period' => 14, 'lead' => 1, 'safety' => 4, 'review' => 3, 'notes' => 'Barang segar, cek stok tiap 3 hari'],
            'SRC-007' => ['period' => 30, 'lead' => 2, 'safety' => 5, 'review' => 5, 'notes' => 'Kuota pangkalan gas datang 2x seminggu'],
            'SRC-009' => ['period' => 30, 'lead' => 2, 'safety' => 15, 'review' => 7, 'notes' => 'Fast moving utama warung'],
            'SRC-016' => ['period' => 30, 'lead' => 2, 'safety' => 12, 'review' => 7, 'notes' => 'Permintaan meningkat saat cuaca panas'],
            'SRC-024' => ['period' => 30, 'lead' => 2, 'safety' => 4, 'review' => 7, 'notes' => 'Kopi saset paling laris pagi & malam'],
            'SRC-045' => ['period' => 30, 'lead' => 3, 'safety' => 3, 'review' => 14, 'notes' => 'Belanja grosir bulanan dari distributor'],
        ];

        $productBySku = collect($seededProducts)->keyBy('sku');
        foreach ($customSettings as $sku => $cfg) {
            $prod = $productBySku->get($sku);
            if (!$prod) {
                continue;
            }

            DB::table('product_restock_settings')->updateOrInsert(
                ['product_id' => $prod['id']],
                [
                    'sales_period_days' => $cfg['period'],
                    'lead_time_days' => $cfg['lead'],
                    'safety_stock' => $cfg['safety'],
                    'review_interval_days' => $cfg['review'],
                    'manual_minimum_stock' => null,
                    'notes' => $cfg['notes'],
                    'created_by' => $ownerId,
                    'updated_by' => $ownerId,
                    'created_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ]
            );
        }

        // 6. Hapus transaksi demo sebelumnya (jika seeder dijalankan ulang) agar tidak dobel
        DB::table('transactions')->where('invoice_number', 'like', 'INV-SRC-%')->delete();

        // 7. Generate deret transaksi 30 hari terakhir (dayOffset 0 = 29 hari lalu, 29 = hari ini)
        $dailyBuckets = []; // [dayOffset => [ ['product_id' => ..., 'qty' => ..., 'price' => ...], ... ]]

        foreach ($seededProducts as $item) {
            $series = $this->generate30DaySeries($item['pattern'], $item['index']);

            foreach ($series as $dayOffset => $qty) {
                if ($qty <= 0) {
                    continue;
                }

                // Pecah pembelian besar menjadi 2-3 kali transaksi dalam sehari agar tampak natural
                $chunks = $qty >= 6 ? [intdiv($qty, 2), $qty - intdiv($qty, 2)] : [$qty];
                foreach ($chunks as $chunkQty) {
                    if ($chunkQty > 0) {
                        $dailyBuckets[$dayOffset][] = [
                            'product_id' => $item['id'],
                            'quantity' => $chunkQty,
                            'price' => $item['sell_price'],
                        ];
                    }
                }
            }
        }

        // Gabungkan item-item harian ke dalam 5-7 struk belanja per hari
        DB::transaction(function () use ($dailyBuckets, $today, $cashierId, $ownerId): void {
            $invoiceSeq = 1;

            for ($dayOffset = 0; $dayOffset < 30; $dayOffset++) {
                $itemsToday = $dailyBuckets[$dayOffset] ?? [];
                if (empty($itemsToday)) {
                    continue;
                }

                shuffle($itemsToday);
                $txDateBase = $today->subDays(29 - $dayOffset);
                $receiptChunks = array_chunk($itemsToday, 4);

                foreach ($receiptChunks as $receiptIdx => $lines) {
                    // Gabungkan product_id yang sama dalam satu struk
                    $mergedLines = [];
                    foreach ($lines as $line) {
                        $pid = $line['product_id'];
                        if (!isset($mergedLines[$pid])) {
                            $mergedLines[$pid] = $line;
                        } else {
                            $mergedLines[$pid]['quantity'] += $line['quantity'];
                        }
                    }

                    $totalPrice = 0;
                    foreach ($mergedLines as $line) {
                        $totalPrice += $line['price'] * $line['quantity'];
                    }

                    $hour = min(21, 7 + ($receiptIdx * 2) + ($dayOffset % 2));
                    $minute = ($invoiceSeq * 7) % 60;
                    $txTime = $txDateBase->setTime($hour, $minute, 0)->toDateTimeString();

                    $isQris = ($invoiceSeq % 4 === 0);
                    $payAmount = $isQris ? $totalPrice : (ceil($totalPrice / 5000) * 5000);
                    $changeAmount = max(0, $payAmount - $totalPrice);

                    $invoiceNumber = sprintf('INV-SRC-%s-%04d', $txDateBase->format('Ymd'), $invoiceSeq++);

                    $transactionId = DB::table('transactions')->insertGetId([
                        'user_id' => ($invoiceSeq % 5 === 0) ? $ownerId : $cashierId,
                        'customer_id' => null,
                        'invoice_number' => $invoiceNumber,
                        'total_price' => $totalPrice,
                        'pay_amount' => $payAmount,
                        'payment_method' => $isQris ? 'qris' : 'cash',
                        'change_amount' => $changeAmount,
                        'discount_id' => null,
                        'discount_amount' => 0,
                        'payment_status' => 'paid',
                        'due_date' => null,
                        'qris_verified_by' => $isQris ? $ownerId : null,
                        'qris_verified_at' => $isQris ? $txTime : null,
                        'created_at' => $txTime,
                        'updated_at' => $txTime,
                    ]);

                    $detailRows = [];
                    foreach ($mergedLines as $line) {
                        $detailRows[] = [
                            'transaction_id' => $transactionId,
                            'product_id' => $line['product_id'],
                            'quantity' => $line['quantity'],
                            'price' => $line['price'],
                            'subtotal' => $line['price'] * $line['quantity'],
                            'created_at' => $txTime,
                            'updated_at' => $txTime,
                        ];
                    }

                    DB::table('transaction_details')->insert($detailRows);
                }
            }
        });

        $this->command?->info('Berhasil membuat 50 produk Warung SRC beserta histori transaksi 30 hari!');
    }

    /**
     * Menghasilkan array penjualan harian 30 hari (index 0 = 29 hari lalu, index 29 = hari ini).
     *
     * @return array<int, int>
     */
    private function generate30DaySeries(string $pattern, int $seed): array
    {
        $series = [];
        $scale = ($seed % 3 === 0) ? 2 : 1; // Variasi volume antar produk

        for ($day = 0; $day < 30; $day++) {
            $qty = match ($pattern) {
                // Tren naik bertahap di paruh kedua bulan
                'trending_up' => $day < 12
                    ? (($day + $seed) % 3 === 0 ? 1 * $scale : 0)
                    : (int) floor(($day - 10) / 4) * $scale + (($day + $seed) % 2),

                // Fluktuatif / lonjakan tajam setiap beberapa hari (memicu buffer MAD)
                'volatile' => (($day + $seed) % 5 === 0)
                    ? (5 + ($seed % 4)) * $scale
                    : ((($day + $seed) % 7 === 0) ? 3 * $scale : 0),

                // Stabil sepanjang bulan
                'steady' => (($day + $seed) % 2 === 0) ? 1 * $scale : (($day % 3 === 0) ? 2 * $scale : 1),

                // Ramai di awal bulan lalu melambat di 12 hari terakhir
                'trending_down' => $day < 15
                    ? (3 - intdiv($day, 6)) * $scale
                    : ($day < 20 && ($day + $seed) % 3 === 0 ? 1 : 0),

                // Penjualan sangat jarang
                default => (($day + $seed) % 11 === 0) ? 1 : 0,
            };

            $series[] = max(0, (int) $qty);
        }

        return $series;
    }
}