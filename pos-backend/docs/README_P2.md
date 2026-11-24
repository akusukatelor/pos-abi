# P2 — Integrasi Kasbon: Laporan, Dashboard, dan Rekomendasi Restok

**Basis:** source LARIS–ABICELL setelah P0 + P1 + P4 + Lint Fix P4. **Jangan** terapkan ke source sebelum patch tersebut.

## Yang berubah

1. **Penjualan** dihitung menurut **tanggal transaksi**, termasuk `payment_method=debt` baik `payment_status=unpaid` maupun `paid`. Tunai dan QRIS hanya dihitung jika status `paid`.
2. **Pembayaran diterima** dihitung menurut **tanggal arus pembayaran**, bukan kapan invoice Kasbon menjadi lunas:
   - nilai penjualan tunai/QRIS saat checkout (`total_price`, bukan uang tunai diserahkan sebelum kembalian),
   - DP Kasbon saat checkout (nilai awal `transactions.pay_amount` setelah dikurangi *seluruh* alokasi pembayaran dari ledger P0),
   - pembayaran/cicilan Kasbon dari `customer_debt_payments` menurut `created_at`.
3. `payment_breakdown`, `sales_trend`, dan `top_products` Dashboard memasukkan penjualan Kasbon, tanpa menganggapnya sudah lunas.
4. **Restok** memasukkan kuantitas penjualan Kasbon meskipun belum lunas; pembayaran cicilan tidak mengubah jumlah barang terjual. Transaksi tunai/QRIS belum lunas tetap tidak dihitung. Pending order online P4 belum dihitung sampai Owner selesai memprosesnya menjadi transaksi.
5. UI **Reports** dan **Dashboard** menampilkan pemisahan omzet, penjualan Kasbon, cicilan yang diterima, dan pembayaran diterima; menghilangkan fallback frontend yang menghitung Kasbon sendiri.

**Tidak ada migration, perubahan P0/P1/P4, atau perubahan flow checkout.**

## File patch (8 file)

| Aksi | File |
|---|---|
| Baru | `pos-backend/app/Services/SalesFinanceService.php` |
| Ubah | `pos-backend/app/Http/Controllers/Api/ReportController.php` |
| Ubah | `pos-backend/app/Http/Controllers/Api/OwnerDashboardController.php` |
| Ubah | `pos-backend/app/Services/RestockRecommendationService.php` |
| Ubah | `pos-backend/app/Http/Controllers/Api/RestockRecommendationController.php` |
| Ubah | `pos-frontend/src/pages/ReportsPage.jsx` |
| Ubah | `pos-frontend/src/pages/DashboardPage.jsx` |
| Baru | `pos-backend/tests/Feature/KasbonReportingIntegrationTest.php` |

## Pemasangan Windows/Laragon

1. Buat backup DB + `git commit` source saat ini. Pastikan hasil P0/P1/P4/lint fix sudah terpasang.
2. Pilih satu cara:
   - Ekstrak ZIP dan salin delapan file sesuai path (review sebelum overwrite jika ada perubahan rekan tim), **atau**
   - Dari root `pos-abi`: `git apply --check P2_Kasbon_Laporan_Restok.patch` lalu `git apply P2_Kasbon_Laporan_Restok.patch`.
3. Backend:

   ```bash
   cd pos-backend
   php artisan optimize:clear
   php artisan test --filter=KasbonReportingIntegrationTest
   php artisan test --filter=ReportControllerTest
   php artisan test --filter=RestockRecommendationControllerTest
   php artisan test --filter=CustomerDebtPaymentTest
   php artisan test --filter=TransactionCheckoutTest
   php artisan test --filter=CustomerEcommerceTest
   ```

4. Frontend:

   ```bash
   cd pos-frontend
   npm run lint
   npm run build
   npm run dev
   ```

**Tidak perlu `php artisan migrate`** untuk P2 dan **jangan menggunakan `migrate:fresh`** pada database nyata.

## Cara memverifikasi lewat UI

- Buat penjualan Kasbon Rp100.000 dengan DP Rp20.000 (jika fitur DP aktif). Pada periode tanggal checkout: omzet +Rp100.000, pembayaran diterima +Rp20.000, piutang baru +Rp80.000. Angka piutang pada dashboard adalah **saldo terkini**, bukan pertambahan piutang pada periode.
- Bayar cicilan Rp30.000 pada tanggal berbeda: **penerimaan periode pelunasan +Rp30.000**, tetapi **omzet periode pelunasan tidak bertambah**.
- Lunasi sisanya: invoice berubah `paid`, tetapi di laporan transaksi tetap masuk **satu kali** ke periode tanggal transaksi pertama dan kategori Kasbon.
- Restok: kuantitas yang dijual lewat Kasbon masuk ke `units_sold` satu kali; membayar cicilan tidak mengubah perhitungan.
- QRIS tetap termasuk penerimaan pembayaran namun bukan **uang tunai fisik**. Field API `cash_received` adalah nama teknis untuk penerimaan melalui tunai/QRIS/pelunasan, bukan saldo kas laci.

## Kontrak respons API (tambahan field tanpa menghapus field lama)

Ditemukan pada `GET /api/reports/summary` di `data.sales`, `GET /api/reports/sales` di `summary`, serta `GET /api/dashboard/owner` di `data.sales`:

- `omzet_barang`: seluruh penjualan valid termasuk Kasbon, dilihat dari tanggal penjualan
- `transaction_count`: jumlah penjualan valid termasuk Kasbon
- `kasbon_sales`, `kasbon_transaction_count`: penjualan awal lewat Kasbon
- `cash_or_qris_sales`: nilai penjualan yang langsung lunas saat checkout
- `kasbon_down_payments`: DP tunai saat awal Kasbon (direkonstruksi dari invoice & ledger P0)
- `kasbon_collections`, `kasbon_collection_count`: pelunasan/cicilan masuk pada rentang tanggal tersebut
- `cash_received`: cash_or_qris_sales + kasbon_down_payments + kasbon_collections

## Status verifikasi

- **Lulus:** PHP syntax seluruh file backend yang diubah/baru; ESLint dua halaman React yang diubah; pemeriksaan `git diff` dan kecocokan patch terhadap baseline.
- **Belum dijalankan:** Laravel Feature Tests/PHPUnit P2 serta build Vite penuh. Lingkungan pembuat patch tidak menyediakan vendor PHP untuk menjalankan suite integrasi; jalankan tes di Laragon.

## Batasan yang penting

- **Riwayat pelunasan sebelum P0:** jika dahulu ada cicilan yang tidak tercatat di tabel ledger P0, sumber nominal DP historis tidak dapat direkonstruksi dengan pasti. Audit/rekonsiliasi manual diperlukan.
- **Void/retur penjualan:** pembatalan administratif penjualan belum ada pada baseline P4. Saat fitur ini dibuat, filter metrik harus ditambah agar transaksi void dikeluarkan dan reversal penerimaan dilakukan dengan benar.
- **Pending online order:** tidak memotong stok dan tidak masuk omzet sampai dibuat transaksi POS saat penyelesaian.
- **Pembelian supplier, margin jasa, laba kotor:** tidak diubah oleh P2. Penerimaan pembayaran di atas tidak identik dengan laba, maupun saldo akhir kas/bank.
- **Ruang lingkup:** layanan Kasbon P0 menerima pelunasan tunai saja. QRIS untuk cicilan membutuhkan verifikasi pembayaran tersendiri.
