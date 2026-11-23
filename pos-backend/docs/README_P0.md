# PATCH P0 — Keamanan Pembayaran Kasbon (LARIS ABICELL)

BASIS: Source code dari `pos-abi(2).zip` (23 Nov 2026). Berlaku untuk file ini, bukan otomatis untuk commit tim yang lebih baru.

## Ruang lingkup
- Owner saja dapat melihat detail pelanggan (termasuk histori finansial) dan mencatat pelunasan.
- Kasir tetap dapat `GET /api/customers` untuk dropdown POS. Kasir tidak dapat `POST /customers/{id}/pay-debt`.
- Pelunasan P0 hanya **Tunai**. QRIS pelunasan ditolak karena belum ada alur verifikasi merchant untuk cicilan.
- Setiap pembayaran menyimpan: customer, owner, nominal, metode, UUID dan hash idempotency, waktu.
- Setiap pembayaran menyimpan alokasi per-invoice (termasuk FIFO).
- UUID yang sama + payload & owner yang sama: HTTP 200 replay, **tanpa pengurangan piutang kedua kali**.
- UUID sama dengan payload atau owner lain: 409 Conflict.
- Pembayaran ditolak jika `customers.total_debt` tidak cocok dengan sisa semua invoice Kasbon. Tidak ada silent deduction.
- Pelanggan dengan transaksi/pembayaran tidak boleh dihapus untuk melindungi riwayat.
- **Tidak mengubah checkout POS, stok, atau QRIS existing**.

## File diubah
- `pos-backend/routes/api.php`
- `pos-backend/app/Http/Controllers/Api/CustomerController.php`
- `pos-backend/app/Models/Customer.php`
- `pos-frontend/src/pages/CustomerPage.jsx`

## File baru
- `pos-backend/database/migrations/2026_11_24_000001_create_customer_debt_payment_ledger.php`
- `pos-backend/app/Models/CustomerDebtPayment.php`
- `pos-backend/app/Models/CustomerDebtPaymentAllocation.php`
- `pos-backend/tests/Feature/CustomerDebtPaymentTest.php`

## Cara pasang (dari folder proyek dengan pos-backend/ dan pos-frontend/)
1. Backup database dan commit/copy source saat ini.
2. Jika cocok dengan ZIP asal, salin file dalam folder patch ke path yang sama; bila tim telah mengubah file, **merge** dengan `.patch` (jangan overwrite kerja terbaru).
3. Dari `pos-backend/`: jalankan `php artisan migrate` (JANGAN migrate:fresh); lalu `php artisan optimize:clear`.
4. Dari `pos-backend/`: jalankan `php artisan test --filter=CustomerDebtPaymentTest` dan suite test checkout.
5. Dari `pos-frontend/`: jalankan `npm ci` bila node_modules belum ada, lalu `npm run build` dan `npm run dev`.
6. Login Owner, buka Customer -> Detail -> Bayar, pilih nominal, simpan. Histori pembayaran muncul di detail pelanggan.
7. Login Kasir: masih boleh ambil daftar pelanggan untuk POS, tetapi tidak boleh memanggil detail maupun bayar utang (403).

## API
`POST /api/customers/{id}/pay-debt` (Bearer token Owner):
```json
{
  "idempotency_key": "5ec89199-d464-4c74-a5cf-51980f346d27",
  "amount": 50000,
  "payment_method": "cash",
  "transaction_id": null
}
```
`transaction_id` opsional; null berarti FIFO. Ulang request **persis sama** dengan UUID yang sama untuk retry aman.
Respons 201: `status, message, data` (customer dengan `debt_payments` dan `transactions`), `payment`, `meta.idempotent_replay=false`.
Respons 200 replay: `meta.idempotent_replay=true`.

## Batasan yang penting
- Data pembayaran kasbon **sebelum migration ini** tidak memiliki ledger terperinci. Jangan mengarang histori lama; mulai audit dari deployment ini.
- Pelunasan QRIS belum diimplementasikan. JANGAN mencatat QRIS sebagai Tunai untuk mengejar fitur.
- Kode menggunakan transaksi database dan row locking. Pengecekan paralel dalam MySQL produksi/staging tetap perlu.
- Bila piutang tidak konsisten dengan invoice, sistem mengembalikan 422; audit/reconcile data di database sebelum menerima pembayaran baru.
- Source audit tidak menggantikan catatan cashflow/laporan kas; integrasi laporan kas bisa dikerjakan terpisah.

## Verifikasi yang dilakukan di lingkungan pembuat patch
- File PHP baru/diubah: pemeriksaan `php -l` berhasil.
- `CustomerPage.jsx`: parse JSX dengan @babel/parser berhasil.
- PHPUnit tidak bisa dieksekusi di runtime ini karena ekstensi PDO SQLite tidak tersedia.
- Build Vite gagal di runtime ini karena modul native Rolldown untuk Linux tidak tersedia dalam node_modules ZIP (arsip berasal dari mesin lain). Lakukan `npm ci` di mesin lokal sebelum build.
