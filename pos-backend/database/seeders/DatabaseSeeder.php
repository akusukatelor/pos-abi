<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
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
