<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $categoryFood = Category::firstOrCreate(['name' => 'Makanan']);
        $categoryDrink = Category::firstOrCreate(['name' => 'Minuman']);
        $categoryHouse = Category::firstOrCreate(['name' => 'Rumah Tangga']);

        $pcs = Unit::firstOrCreate(['name' => 'Pieces', 'short_name' => 'pcs']);
        $box = Unit::firstOrCreate(['name' => 'Box', 'short_name' => 'box']);
        $btl = Unit::firstOrCreate(['name' => 'Botol', 'short_name' => 'btl']);

        Product::firstOrCreate(
            ['sku_code' => 'SKU-FOOD-001'],
            [
                'category_id' => $categoryFood->id,
                'unit_id' => $pcs->id,
                'name' => 'Keripik Kentang',
                'buy_price' => 12000,
                'sell_price' => 18000,
                'stock' => 8,
                'minimum_stock' => 12,
            ]
        );

        Product::firstOrCreate(
            ['sku_code' => 'SKU-DRINK-001'],
            [
                'category_id' => $categoryDrink->id,
                'unit_id' => $btl->id,
                'name' => 'Air Mineral 600ml',
                'buy_price' => 4000,
                'sell_price' => 7000,
                'stock' => 24,
                'minimum_stock' => 12,
            ]
        );

        Product::firstOrCreate(
            ['sku_code' => 'SKU-HOUSE-001'],
            [
                'category_id' => $categoryHouse->id,
                'unit_id' => $box->id,
                'name' => 'Detergen Bubuk',
                'buy_price' => 18500,
                'sell_price' => 26000,
                'stock' => 4,
                'minimum_stock' => 10,
            ]
        );

        Product::firstOrCreate(
            ['sku_code' => 'SKU-FOOD-002'],
            [
                'category_id' => $categoryFood->id,
                'unit_id' => $pcs->id,
                'name' => 'Biskuit Cokelat',
                'buy_price' => 15000,
                'sell_price' => 23000,
                'stock' => 15,
                'minimum_stock' => 10,
            ]
        );

        $accounts = [
            [
                'label' => 'Owner',
                'name' => env('OWNER_NAME', 'Owner Toko Abi'),
                'email' => env('OWNER_EMAIL'),
                'password' => env('OWNER_PASSWORD'),
                'role' => 'admin',
            ],
            [
                'label' => 'Kasir',
                'name' => env('KASIR_NAME', 'Kasir Toko Abi'),
                'email' => env('KASIR_EMAIL'),
                'password' => env('KASIR_PASSWORD'),
                'role' => 'kasir',
            ],
        ];

        // Pastikan email Owner dan Kasir berbeda.
        if (
            filled($accounts[0]['email']) &&
            filled($accounts[1]['email']) &&
            strcasecmp(
                trim($accounts[0]['email']),
                trim($accounts[1]['email'])
            ) === 0
        ) {
            throw new \RuntimeException(
                'Email Owner dan Kasir harus berbeda.'
            );
        }

        foreach ($accounts as $account) {
            if (
                blank($account['email']) ||
                blank($account['password'])
            ) {
                $this->command?->warn(
                    "Akun {$account['label']} dilewati. "
                    . "Periksa konfigurasi .env."
                );

                continue;
            }

            User::updateOrCreate(
                ['email' => trim($account['email'])],
                [
                    'name' => $account['name'],
                    'password' => Hash::make($account['password']),
                    'role' => $account['role'],
                    'is_active' => true,
                ]
            );

            $this->command?->info(
                "Akun {$account['label']} berhasil disiapkan."
            );
        }
    }
}
