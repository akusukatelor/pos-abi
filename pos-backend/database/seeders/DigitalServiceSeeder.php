<?php

namespace Database\Seeders;

use App\Models\ServiceMarginLog;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DigitalServiceSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->whereIn('role', ['admin', 'owner'])->first() ?? User::query()->first();
        $kasir = User::query()->where('role', 'kasir')->first() ?? $owner;

        if (!$owner) {
            $this->command?->warn('User belum tersedia. Jalankan seeder user terlebih dahulu.');
            return;
        }

        $now = CarbonImmutable::now();

        DB::transaction(function () use ($owner, $kasir, $now): void {
            // =================================================================
            // 1. MASTER JENIS LAYANAN DIGITAL (PPOB & BRILINK)
            // =================================================================
            $serviceTypeSpecs = [
                [
                    'name' => 'Transfer Bank BRILink',
                    'description' => 'Transfer sesama BRI & antar bank (Admin Rp 3.000 – Rp 10.000)',
                    'is_active' => true,
                ],
                [
                    'name' => 'Tarik Tunai BRILink',
                    'description' => 'Tarik tunai kartu ATM BRI & bank lain via EDC BRILink',
                    'is_active' => true,
                ],
                [
                    'name' => 'Token Listrik PLN Prabayar',
                    'description' => 'Pembelian token listrik PLN denom 20rb s/d 1jt (Admin Rp 3.000)',
                    'is_active' => true,
                ],
                [
                    'name' => 'Top Up E-Wallet (DANA / GoPay / OVO / ShopeePay)',
                    'description' => 'Isi saldo dompet digital (Admin Rp 2.000 – Rp 3.000)',
                    'is_active' => true,
                ],
                [
                    'name' => 'Pulsa & Paket Data All Operator',
                    'description' => 'Isi ulang pulsa & kuota Telkomsel, Indosat, XL, Tri, Smartfren',
                    'is_active' => true,
                ],
                [
                    'name' => 'Tagihan Listrik, BPJS & PDAM Tirta Satria',
                    'description' => 'Pembayaran tagihan bulanan PLN Pascabayar, BPJS Kesehatan & PDAM Banyumas',
                    'is_active' => true,
                ],
                [
                    'name' => 'Voucher Game Fisik',
                    'description' => 'Layanan lama (dialihkan ke Top Up E-Wallet)',
                    'is_active' => false,
                ],
            ];

            $types = [];
            foreach ($serviceTypeSpecs as $spec) {
                $types[$spec['name']] = ServiceType::query()->updateOrCreate(
                    ['name' => $spec['name']],
                    [
                        'description' => $spec['description'],
                        'is_active' => $spec['is_active'],
                    ]
                );
            }

            // =================================================================
            // 2. RIWAYAT TRANSAKSI LAYANAN & LOG VERIFIKASI MARGIN
            // =================================================================
            $transactions = [
                // Terverifikasi (masuk laporan keuangan)
                [
                    'code' => 'SVC-DEMO-0001',
                    'type' => 'Transfer Bank BRILink',
                    'user_id' => $kasir->id,
                    'reference_number' => 'BRI-88492011',
                    'service_at' => $now->subDays(9)->setTime(9, 40),
                    'nominal_amount' => 500000,
                    'initial_margin' => 5000,
                    'verified_margin' => 5000,
                    'verified_by' => $owner->id,
                    'verified_at' => $now->subDays(9)->setTime(18, 10),
                    'verify_note' => 'Sesuai mutasi EDC BRILink harian',
                    'notes' => 'Transfer ke rekening BRI a.n. Ibu Suryati',
                    'status' => 'completed',
                ],
                [
                    'code' => 'SVC-DEMO-0002',
                    'type' => 'Tarik Tunai BRILink',
                    'user_id' => $kasir->id,
                    'reference_number' => 'TRK-77310942',
                    'service_at' => $now->subDays(7)->setTime(14, 15),
                    'nominal_amount' => 1000000,
                    'initial_margin' => 10000,
                    'verified_margin' => 10000,
                    'verified_by' => $owner->id,
                    'verified_at' => $now->subDays(7)->setTime(19, 0),
                    'verify_note' => 'Fee EDC terkonfirmasi',
                    'notes' => 'Tarik tunai kartu ATM BNI',
                    'status' => 'completed',
                ],
                [
                    'code' => 'SVC-DEMO-0003',
                    'type' => 'Token Listrik PLN Prabayar',
                    'user_id' => $owner->id,
                    'reference_number' => '52104892104',
                    'service_at' => $now->subDays(5)->setTime(11, 5),
                    'nominal_amount' => 100000,
                    'initial_margin' => 2500,
                    'verified_margin' => 3000,
                    'verified_by' => $owner->id,
                    'verified_at' => $now->subDays(5)->setTime(11, 20),
                    'verify_note' => 'Koreksi selisih modal server pulsa Rp 500',
                    'notes' => 'Token PLN 100rb Pak RT 03',
                    'status' => 'completed',
                ],
                [
                    'code' => 'SVC-DEMO-0004',
                    'type' => 'Tagihan Listrik, BPJS & PDAM Tirta Satria',
                    'user_id' => $kasir->id,
                    'reference_number' => 'PDAM-0411982',
                    'service_at' => $now->subDays(4)->setTime(16, 30),
                    'nominal_amount' => 142500,
                    'initial_margin' => 3000,
                    'verified_margin' => 3000,
                    'verified_by' => $owner->id,
                    'verified_at' => $now->subDays(4)->setTime(20, 0),
                    'verify_note' => 'Struk pembayaran lunas terverifikasi',
                    'notes' => 'Tagihan PDAM Tirta Satria Banyumas',
                    'status' => 'completed',
                ],
                [
                    'code' => 'SVC-DEMO-0005',
                    'type' => 'Top Up E-Wallet (DANA / GoPay / OVO / ShopeePay)',
                    'user_id' => $kasir->id,
                    'reference_number' => '085747120091',
                    'service_at' => $now->subDays(2)->setTime(13, 12),
                    'nominal_amount' => 200000,
                    'initial_margin' => 2000,
                    'verified_margin' => 2500,
                    'verified_by' => $owner->id,
                    'verified_at' => $now->subDays(2)->setTime(17, 45),
                    'verify_note' => 'Admin top up DANA Rp 2.500',
                    'notes' => 'Top up DANA pelanggan',
                    'status' => 'completed',
                ],
                [
                    'code' => 'SVC-DEMO-0006',
                    'type' => 'Pulsa & Paket Data All Operator',
                    'user_id' => $owner->id,
                    'reference_number' => '081226881902',
                    'service_at' => $now->subDay()->setTime(10, 50),
                    'nominal_amount' => 50000,
                    'initial_margin' => 2000,
                    'verified_margin' => 2000,
                    'verified_by' => $owner->id,
                    'verified_at' => $now->subDay()->setTime(12, 0),
                    'verify_note' => 'Margin server sesuai',
                    'notes' => 'Pulsa Telkomsel 50rb',
                    'status' => 'completed',
                ],

                // Belum diverifikasi Owner (siap diklik tombol "Verifikasi" saat demo)
                [
                    'code' => 'SVC-DEMO-0007',
                    'type' => 'Transfer Bank BRILink',
                    'user_id' => $kasir->id,
                    'reference_number' => 'BRI-99201488',
                    'service_at' => $now->subHours(3),
                    'nominal_amount' => 350000,
                    'initial_margin' => 5000,
                    'verified_margin' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                    'verify_note' => null,
                    'notes' => 'Transfer antar bank BCA, menunggu cek mutasi Owner',
                    'status' => 'completed',
                ],
                [
                    'code' => 'SVC-DEMO-0008',
                    'type' => 'Token Listrik PLN Prabayar',
                    'user_id' => $kasir->id,
                    'reference_number' => '32190481129',
                    'service_at' => $now->subHours(2),
                    'nominal_amount' => 50000,
                    'initial_margin' => 3000,
                    'verified_margin' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                    'verify_note' => null,
                    'notes' => 'Token PLN 50rb pelanggan',
                    'status' => 'completed',
                ],
                [
                    'code' => 'SVC-DEMO-0009',
                    'type' => 'Top Up E-Wallet (DANA / GoPay / OVO / ShopeePay)',
                    'user_id' => $kasir->id,
                    'reference_number' => '081391004421',
                    'service_at' => $now->subMinutes(40),
                    'nominal_amount' => 100000,
                    'initial_margin' => null,
                    'verified_margin' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                    'verify_note' => null,
                    'notes' => 'Top up GoPay pelanggan',
                    'status' => 'completed',
                ],

                // Dibatalkan (untuk menunjukkan jejak audit pembatalan)
                [
                    'code' => 'SVC-DEMO-0010',
                    'type' => 'Transfer Bank BRILink',
                    'user_id' => $kasir->id,
                    'reference_number' => 'BRI-ERR-0041',
                    'service_at' => $now->subDays(3)->setTime(15, 5),
                    'nominal_amount' => 250000,
                    'initial_margin' => 5000,
                    'verified_margin' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                    'verify_note' => null,
                    'notes' => 'Percobaan transfer pertama',
                    'status' => 'cancelled',
                    'cancelled_by' => $owner->id,
                    'cancelled_at' => $now->subDays(3)->setTime(15, 18),
                    'cancellation_reason' => 'Transaksi gagal/time-out di mesin EDC dan dana tidak terpotong.',
                ],
            ];

            foreach ($transactions as $spec) {
                $hash = hash('sha256', 'seed:service-tx:'.$spec['code']);

                if (ServiceTransaction::query()->where('idempotency_hash', $hash)->exists()) {
                    continue;
                }

                $serviceType = $types[$spec['type']] ?? reset($types);
                $isVerified = $spec['verified_by'] !== null;
                $finalMargin = $isVerified ? $spec['verified_margin'] : $spec['initial_margin'];

                $tx = ServiceTransaction::create([
                    'service_type_id' => $serviceType->id,
                    'user_id' => $spec['user_id'],
                    'reference_number' => $spec['reference_number'],
                    'service_at' => $spec['service_at'],
                    'nominal_amount' => number_format((float) $spec['nominal_amount'], 2, '.', ''),
                    'margin_amount' => $finalMargin !== null ? number_format((float) $finalMargin, 2, '.', '') : null,
                    'margin_verified_by' => $spec['verified_by'],
                    'margin_verified_at' => $spec['verified_at'],
                    'notes' => $spec['notes'],
                    'status' => $spec['status'],
                    'cancelled_by' => $spec['cancelled_by'] ?? null,
                    'cancelled_at' => $spec['cancelled_at'] ?? null,
                    'cancellation_reason' => $spec['cancellation_reason'] ?? null,
                    'idempotency_key' => (string) Str::uuid(),
                    'idempotency_hash' => $hash,
                ]);

                if ($isVerified) {
                    ServiceMarginLog::create([
                        'service_transaction_id' => $tx->id,
                        'changed_by' => $spec['verified_by'],
                        'old_margin_amount' => $spec['initial_margin'] !== null
                            ? number_format((float) $spec['initial_margin'], 2, '.', '')
                            : null,
                        'new_margin_amount' => number_format((float) $spec['verified_margin'], 2, '.', ''),
                        'note' => $spec['verify_note'],
                    ]);
                }
            }
        }, 3);

        $this->command?->info('Berhasil mengisi data Jenis Layanan Digital dan Transaksi PPOB/BRILink.');
    }
}