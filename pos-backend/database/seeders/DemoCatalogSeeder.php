<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ServiceType;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoCatalogSeeder extends Seeder
{
    /**
     * Seed sample catalog data for local development and UI testing only.
     *
     * Existing products are never overwritten and existing stock is never changed.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoCatalogSeeder tidak boleh dijalankan pada environment production.');

            return;
        }

        $owner = User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$owner) {
            throw new RuntimeException(
                'Akun Owner aktif tidak ditemukan. Siapkan akun Owner terlebih dahulu sebelum menjalankan DemoCatalogSeeder.'
            );
        }

        DB::transaction(function () use ($owner): void {
            $categories = [];
            foreach (['Sembako', 'Makanan & Minuman', 'Air Minum', 'Perawatan & Kebersihan'] as $name) {
                $categories[$name] = Category::query()->firstOrCreate(['name' => $name]);
            }

            $units = [];
            foreach ([
                ['name' => 'Pak (5 kg)', 'short_name' => 'pak'],
                ['name' => 'Pouch (1 L)', 'short_name' => 'pouch'],
                ['name' => 'Kilogram', 'short_name' => 'kg'],
                ['name' => 'Bungkus', 'short_name' => 'bks'],
                ['name' => 'Galon', 'short_name' => 'galon'],
                ['name' => 'Batang', 'short_name' => 'btg'],
                ['name' => 'Sachet', 'short_name' => 'sachet'],
            ] as $unitData) {
                $units[$unitData['name']] = Unit::query()->firstOrCreate(
                    ['name' => $unitData['name']],
                    ['short_name' => $unitData['short_name']]
                );
            }

            $products = [
                [
                    'sku_code' => 'DEMO-BR-5KG',
                    'name' => 'Beras Premium 5 kg',
                    'category' => 'Sembako',
                    'unit' => 'Pak (5 kg)',
                    'buy_price' => 68000,
                    'sell_price' => 75000,
                    'initial_stock' => 20,
                ],
                [
                    'sku_code' => 'DEMO-MG-1L',
                    'name' => 'Minyak Goreng 1 L',
                    'category' => 'Sembako',
                    'unit' => 'Pouch (1 L)',
                    'buy_price' => 16000,
                    'sell_price' => 18000,
                    'initial_stock' => 25,
                ],
                [
                    'sku_code' => 'DEMO-GP-1KG',
                    'name' => 'Gula Pasir 1 kg',
                    'category' => 'Sembako',
                    'unit' => 'Kilogram',
                    'buy_price' => 15500,
                    'sell_price' => 18000,
                    'initial_stock' => 20,
                ],
                [
                    'sku_code' => 'DEMO-IND-GRG',
                    'name' => 'Indomie Goreng',
                    'category' => 'Makanan & Minuman',
                    'unit' => 'Bungkus',
                    'buy_price' => 2700,
                    'sell_price' => 3500,
                    'initial_stock' => 60,
                ],
                [
                    'sku_code' => 'DEMO-IND-AYB',
                    'name' => 'Indomie Ayam Bawang',
                    'category' => 'Makanan & Minuman',
                    'unit' => 'Bungkus',
                    'buy_price' => 2600,
                    'sell_price' => 3500,
                    'initial_stock' => 40,
                ],
                [
                    'sku_code' => 'DEMO-AQUA-GAL',
                    'name' => 'Aqua Galon',
                    'category' => 'Air Minum',
                    'unit' => 'Galon',
                    'buy_price' => 17000,
                    'sell_price' => 22000,
                    'initial_stock' => 12,
                ],
                [
                    'sku_code' => 'DEMO-SAB-MND',
                    'name' => 'Sabun Mandi',
                    'category' => 'Perawatan & Kebersihan',
                    'unit' => 'Batang',
                    'buy_price' => 4200,
                    'sell_price' => 5000,
                    'initial_stock' => 30,
                ],
                [
                    'sku_code' => 'DEMO-KOP-SCH',
                    'name' => 'Kopi Sachet',
                    'category' => 'Makanan & Minuman',
                    'unit' => 'Sachet',
                    'buy_price' => 1000,
                    'sell_price' => 1500,
                    'initial_stock' => 80,
                ],
            ];

            $createdProducts = 0;
            $skippedProducts = 0;

            foreach ($products as $productData) {
                $product = Product::query()->firstOrCreate(
                    ['sku_code' => $productData['sku_code']],
                    [
                        'category_id' => $categories[$productData['category']]->id,
                        'unit_id' => $units[$productData['unit']]->id,
                        'name' => $productData['name'],
                        'buy_price' => $productData['buy_price'],
                        'sell_price' => $productData['sell_price'],
                        'stock' => 0,
                        'is_active' => true,
                    ]
                );

                if (!$product->wasRecentlyCreated) {
                    $skippedProducts++;

                    continue;
                }

                $initialStock = (int) $productData['initial_stock'];

                if ($initialStock > 0) {
                    StockMovement::query()->create([
                        'product_id' => $product->id,
                        'user_id' => $owner->id,
                        'type' => 'in',
                        'quantity' => $initialStock,
                        'description' => 'Stok awal data demo dari DemoCatalogSeeder.',
                    ]);

                    $product->increment('stock', $initialStock);
                }

                $createdProducts++;
            }

            $serviceTypes = [
                [
                    'name' => 'Pulsa & Paket Data',
                    'description' => 'Pencatatan transaksi pengisian pulsa dan paket data.',
                ],
                [
                    'name' => 'Token PLN',
                    'description' => 'Pencatatan pembelian token listrik prabayar.',
                ],
                [
                    'name' => 'Pembayaran Tagihan PPOB',
                    'description' => 'Pencatatan pembayaran tagihan melalui layanan PPOB.',
                ],
                [
                    'name' => 'BRILink Transfer',
                    'description' => 'Pencatatan layanan transfer melalui BRILink.',
                ],
            ];

            $createdServices = 0;
            foreach ($serviceTypes as $serviceTypeData) {
                $serviceType = ServiceType::query()->firstOrCreate(
                    ['name' => $serviceTypeData['name']],
                    [
                        'description' => $serviceTypeData['description'],
                        'is_active' => true,
                    ]
                );

                if ($serviceType->wasRecentlyCreated) {
                    $createdServices++;
                }
            }

            $this->command?->info("Produk demo baru: {$createdProducts}; produk yang sudah ada dilewati: {$skippedProducts}.");
            $this->command?->info("Jenis layanan digital baru: {$createdServices}.");
            $this->command?->warn('Harga dan stok pada seeder ini adalah data DEMO, bukan data stok riil Toko Abi.');
        });
    }
}
