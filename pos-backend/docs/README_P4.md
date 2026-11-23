# P4 — Customer Login, E-Commerce MVP & Fondasi Loyalty — LARIS ABICELL

## Basis patch

**Patch ini dibuat di atas kode setelah P0 + P1** pada project `pos-abi` yang memiliki dua folder `pos-backend/` dan `pos-frontend/`. Jangan menerapkan P4 langsung di ZIP lama sebelum P0/P1.

Fitur loyalty **belum memberikan atau menukar poin**. Nilai earn/redeem belum disetujui klien. `/api/customer/loyalty` menampilkan saldo ledger dan status `enabled: false`; tabel ledger sengaja tidak ditulis otomatis oleh checkout.

## Lingkup P4

1. **Customer account terpisah dari Owner/Kasir.** Register/login/logout memakai tabel `customer_accounts` dan Sanctum token terpisah, terhubung dengan tabel `customers`.
2. **Katalog publik**, hanya `id`, `name`, `price`, dan boolean `available`; tidak membuka angka stok, harga beli, atau margin.
3. **Keranjang dan pesan online.** Pelanggan login untuk membuat pesanan. Metode pembayaran saat ini **tunai saat ambil di toko**. Order awal `pending/unpaid`, belum mengurangi stok.
4. **Owner memproses pesanan.** Hanya Owner bisa klik **Terima Tunai & Selesaikan** setelah uang diterima dan barang diserahkan. Backend mensyaratkan konfirmasi eksplisit dan mengecek stok ulang. Dalam satu DB transaction: buat transaksi POS `cash/paid`, detail transaksi, stock movement, dan kurangi stok satu kali.
5. **Pembatalan Owner.** Pesanan pending dapat dibatalkan dengan alasan, tanpa perubahan stok.
6. **Riwayat pesanan customer** hanya milik akun tersebut. Owner dapat melihat semua pesanan dari menu **Pesanan Online**.
7. **Fondasi loyalty ledger**, tanpa angka fiktif, tanpa redemption, tanpa otomatis earn.
8. **CustomerPage Owner** menampilkan email akun online yang tertaut dan saldo loyalty (jika ada).

## Cara instal (Windows / Laragon)

1. **Backup database**, `git status`, lalu commit P0 + P1 dan perubahan terbaru tim.
2. Pilih **salah satu** cara:
   - **Git patch:** dari root `pos-abi/`, `git apply --check P4_Ecommerce_Perubahan.patch`, kemudian `git apply P4_Ecommerce_Perubahan.patch`.
   - **Salin file:** ekstrak ZIP dan salin isinya dari folder `P4_Ecommerce_Patch/pos-backend/` serta `P4_Ecommerce_Patch/pos-frontend/` ke folder project. Tinjau konflik jika teman mengedit file yang sama; jangan replace sembarangan.
3. Dari folder `pos-backend`:
   ```bash
   php artisan migrate
   php artisan optimize:clear
   php artisan route:list --path=customer
   php artisan route:list --path=store
   php artisan route:list --path=admin/online-orders
   php artisan test --filter=CustomerEcommerceTest
   php artisan test --filter=CustomerDebtPaymentTest
   php artisan test --filter=PublicChatbotTest
   php artisan test --filter=TransactionCheckoutTest
   ```
4. Dari folder `pos-frontend`:
   ```bash
   npm install
   npm run lint
   npm run build
   npm run dev
   ```
5. Di browser:
   - **Toko pelanggan:** `http://localhost:5173/?halaman=toko`
   - **Bantuan FAQ:** `http://localhost:5173/?halaman=bantuan`
   - **Owner:** `http://localhost:5173/`, masuk Owner dan pilih menu `Pesanan Online`.

**JANGAN jalankan `php artisan migrate:fresh` di database berisi data toko.**

## Endpoint utama

| Route | Akses |
|---|---|
| GET `/api/store/catalog` | Publik, throttled |
| POST `/api/customer/register`, POST `/api/customer/login` | Publik, throttled |
| GET `/api/customer/me`, POST `/api/customer/logout` | Customer terautentikasi |
| POST `/api/customer/orders`, GET `/api/customer/orders` | Customer terautentikasi, hanya order miliknya |
| GET `/api/customer/loyalty` | Customer terautentikasi |
| GET `/api/admin/online-orders` | Owner |
| POST `/api/admin/online-orders/{id}/complete` | Owner, konfirmasi penerimaan tunai & serah barang |
| POST `/api/admin/online-orders/{id}/cancel` | Owner, alasan wajib |

Catatan keamanan: seluruh API staf eksisting kini memakai `auth:sanctum`, `active`, **dan `role:admin,kasir`** pada group terluarnya; token customer tidak bisa mengakses inventory/internal, laporan, atau kasbon.

## Data model baru

- `customer_accounts` — user customer saja, unique `customer_id` dan `email`.
- `online_orders` — `pending`, `completed`, `cancelled`, total snapshot, UUID idempotency, link transaksi POS setelah selesai.
- `online_order_items` — detail dan harga snapshot saat order.
- `loyalty_point_transactions` — mutasi poin signed; referensi online order maupun transaksi POS untuk implementasi aturan di masa depan.

Tidak ada kolom baru pada tabel `transactions` dan `customers`; history Kasbon dari P0 tetap aman.

## Batasan yang perlu disetujui klien sebelum produksi

- **Loyalty:** rumus earning, waktu pemberian, pembatalan/reversal poin, batas redeem, masa berlaku, pelanggan POS vs online, program peluncuran. Belum boleh diaktifkan tanpa keputusan ini.
- **Pembayaran:** hanya tunai ketika ambil. Tidak ada pembayaran QRIS online, kartu, gateway, atau kasbon online. Pelanggan belum bisa memilih pengiriman.
- **Pengambilan:** jam/alamat/kontak toko belum dikonfirmasi, jadi tidak dikarang di halaman.
- **Akun:** belum ada email verification/password reset; perlu disiapkan untuk production. Akun baru tidak otomatis ditautkan ke catatan pelanggan offline hanya dengan nomor HP (mencegah pembajakan kasbon/loyalty). Perlu prosedur verifikasi Owner bila ingin merge.
- **Stok:** pending order tidak melakukan reservasi stok; stok dicek ulang ketika Owner menyelesaikan order. Jika habis, Owner perlu menghubungi pelanggan sebelum menerima uang.
- **Harga:** harga disimpan saat pelanggan membuat pesanan, belum ada kebijakan batas masa berlaku pesanan/harga.
- **Operasional:** backup, HTTPS, CORS, rate limit persisten, verifikasi alamat toko dan perangkat, uji browser/UAT tetap diperlukan.

## Uji manual yang disarankan

1. Sebagai guest buka toko; tidak ada harga beli/stok numerik di response `store/catalog`.
2. Registrasi akun baru; coba email sama kedua kali (harus gagal validasi).
3. Login customer; buat pesanan; pastikan order pending dan belum mengurangi stok.
4. Login Owner di tab lain; buka Pesanan Online, konfirmasi penerimaan **tunai** serta penyerahan barang.
5. Cek order selesai, stok turun sesuai qty, record transaksi/ledger hanya satu; klik ulang complete tidak menggandakan.
6. Login customer lain; coba akses order akun pertama (404).
7. Pastikan Customer token mengakses `/api/customers` menerima 403, bukan data internal.
8. Buka Loyalty; seharusnya **0 poin / belum aktif**, bukan reward otomatis.

## Status pemeriksaan pembuat patch

- `php -l`: lulus untuk file PHP yang dibuat dan diubah.
- `php artisan route:list`: route baru berhasil terdaftar.
- ESLint: lulus untuk file frontend yang disentuh patch.
- Parsing JSX: lulus.
- **Belum menjalankan 12 Feature Test di lingkungan ini** karena ekstensi PHP DOM, mbstring, dan XMLWriter tidak tersedia. Jalankan di Laragon.
- **Belum berhasil menjalankan Vite build di lingkungan ini** karena optional native binding Rolldown tidak tersedia; jalankan `npm install` dan `npm run build` di komputer pengembang.
