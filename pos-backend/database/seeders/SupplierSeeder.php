<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $now = CarbonImmutable::now()->toDateTimeString();
        $columns = array_flip(Schema::getColumnListing('suppliers'));

        // Bersihkan nama supplier versi umum sebelumnya jika belum terpakai di transaksi pembelian
        $legacyNames = [
            'Mitra Grosir SRC Sumber Makmur',
            'Agen Sembako & Beras Karya Tani',
            'Pangkalan Gas LPG 3 Kg Berkah Jaya',
            'PT Indomarco Adi Prima (Distributor Indofood)',
            'PT Sayap Mas Utama (Distributor Wings)',
            'CV Tirta Kencana (Distributor Unilever)',
            'PT Tirta Investama (Distributor Aqua)',
            'PT Sinar Sosro Kantor Penjualan',
            'PT Santos Jaya Abadi (Distributor Kapal Api)',
            'PT Mayora Indah Tbk (Depo Distribusi)',
            'CV Aneka Snack & Biskuit Nusantara',
            'UD Sehat Sentosa (Grosir Obat Warung & ATK)',
        ];

        try {
            DB::table('suppliers')->whereIn('name', $legacyNames)->delete();
        } catch (Throwable) {
            // Abaikan jika salah satu data sudah berelasi dengan purchase_receipts
        }

        $suppliers = [
            [
                'name' => 'Paguyuban Grosir SRC Banyumas Raya',
                'contact_person' => 'Bapak Hendra Wijaya',
                'phone' => '0812-2789-4410',
                'address' => 'Jl. Gerilya Timur No. 45, Berkoh, Kec. Purwokerto Selatan, Kab. Banyumas',
            ],
            [
                'name' => 'Agen Beras & Sembako Karya Tani Sokaraja',
                'contact_person' => 'Ibu Hj. Siti Aminah',
                'phone' => '0813-9104-8821',
                'address' => 'Pasar Wage Blok B No. 12, Jl. Brigjen Katamso, Kec. Purwokerto Timur, Kab. Banyumas',
            ],
            [
                'name' => 'Pangkalan Gas LPG 3 Kg Berkah Kedungwuluh',
                'contact_person' => 'Bapak Slamet Riyadi',
                'phone' => '0857-4312-9098',
                'address' => 'Jl. Veteran No. 18, Kel. Kedungwuluh, Kec. Purwokerto Barat, Kab. Banyumas',
            ],
            [
                'name' => 'PT Indomarco Adi Prima - Cabang Purwokerto',
                'contact_person' => 'Mas Dimas Pratama',
                'phone' => '0821-3654-1120',
                'address' => 'Jl. Raya Sokaraja - Banyumas Km. 3, Karangnanas, Kec. Sokaraja, Kab. Banyumas',
            ],
            [
                'name' => 'PT Sayap Mas Utama (Depo Wings Purwokerto)',
                'contact_person' => 'Bapak Agus Setiawan',
                'phone' => '0812-1540-7732',
                'address' => 'Jl. Sultan Agung No. 88, Karangklesem, Kec. Purwokerto Selatan, Kab. Banyumas',
            ],
            [
                'name' => 'CV Tirta Kencana Banyumas (Distributor Unilever)',
                'contact_person' => 'Mbak Rina Kartika',
                'phone' => '0852-2890-3341',
                'address' => 'Jl. Gatot Subroto No. 102, Sokanegara, Kec. Purwokerto Timur, Kab. Banyumas',
            ],
            [
                'name' => 'Depo Air Mineral Aqua & Vit Purwokerto',
                'contact_person' => 'Bapak Wahyu Nugroho',
                'phone' => '0813-2700-6519',
                'address' => 'Jl. Raya Kalibagor No. 24, Kalibagor, Kec. Kalibagor, Kab. Banyumas',
            ],
            [
                'name' => 'PT Sinar Sosro KPW Purwokerto',
                'contact_person' => 'Bapak Eko Prasetyo',
                'phone' => '0819-0345-8812',
                'address' => 'Jl. Jenderal Sudirman Timur No. 512, Berkoh, Kec. Purwokerto Selatan, Kab. Banyumas',
            ],
            [
                'name' => 'Distributor Kopi Kapal Api & ABC Banyumas',
                'contact_person' => 'Mas Reza Pahlevi',
                'phone' => '0822-4190-5567',
                'address' => 'Jl. Pahlawan No. 64, Tanjung, Kec. Purwokerto Selatan, Kab. Banyumas',
            ],
            [
                'name' => 'Depo Distribusi Mayora Purwokerto',
                'contact_person' => 'Ibu Dewi Lestari',
                'phone' => '0812-8871-2043',
                'address' => 'Jl. Overste Isdiman No. 19, Purwokerto Lor, Kec. Purwokerto Timur, Kab. Banyumas',
            ],
            [
                'name' => 'CV Aneka Snack & Biskuit Satria Purwokerto',
                'contact_person' => 'Bapak Fajar Ramadhan',
                'phone' => '0856-4721-9904',
                'address' => 'Jl. Raya Beji No. 14, Karangsalam Kidul, Kec. Kedungbanteng, Kab. Banyumas',
            ],
            [
                'name' => 'UD Sehat Sentosa Pasar Wage (Grosir Obat & Kelontong)',
                'contact_person' => 'Ibu Ratna Sari',
                'phone' => '0813-9288-1405',
                'address' => 'Ruko Pasar Wage No. 31, Jl. Vihara, Purwokerto Wetan, Kec. Purwokerto Timur, Kab. Banyumas',
            ],
        ];

        foreach ($suppliers as $supplier) {
            $payload = array_intersect_key([
                'contact_person' => $supplier['contact_person'],
                'phone' => $supplier['phone'],
                'address' => $supplier['address'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $columns);

            DB::table('suppliers')->updateOrInsert(
                ['name' => $supplier['name']],
                $payload
            );
        }

        $this->command?->info('Berhasil mengisi 12 data supplier Warung SRC wilayah Purwokerto.');
    }
}