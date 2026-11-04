# Tahap 2 — QRIS GoPay Merchant dengan Verifikasi Manual

## Ringkasan

Implementasi QRIS manual telah ditambahkan pada backend POS Toko Abi.

## Alur operasional

1. Kasir membuat keranjang dan memilih `qris_gopay`.
2. Kasir menampilkan QRIS merchant kepada pelanggan.
3. Pelanggan membayar.
4. Kasir memeriksa transaksi masuk pada aplikasi GoPay Merchant dan mencocokkan nominal serta status.
5. Setelah yakin pembayaran diterima, Kasir mengirim checkout dengan `manual_verification_confirmed: true`.
6. Backend membuat transaksi `paid`, menyimpan identitas/waktu verifikasi, mengurangi stok, dan mencatat stock movement secara atomik.

Backend tidak menghubungi GoPay Merchant dan tidak melakukan verifikasi otomatis. Identitas verifikator diambil dari user yang sedang login; client tidak dapat menentukan `qris_verified_by`.

## Kontrak request QRIS

`POST /api/transactions` — memerlukan autentikasi Sanctum.

```json
{
  "idempotency_key": "a6fcbf77-b1b8-44f6-aaf4-8b6e7a9b8f21",
  "payment_method": "qris_gopay",
  "pay_amount": 10000,
  "manual_verification_confirmed": true,
  "payment_reference": "REFERENSI-MERCHANT-JIKA-TERSEDIA",
  "items": [
    { "product_id": 1, "quantity": 2 }
  ]
}
```

Ketentuan:
- `manual_verification_confirmed` wajib bernilai accepted untuk QRIS.
- `pay_amount` wajib sama persis dengan total yang dihitung backend.
- `payment_reference` opsional, diisi jika referensi transaksi tersedia di aplikasi merchant.
- `qris_verified_by` dan `qris_verified_at` ditentukan server, bukan client.
- Pengiriman ulang payload dengan idempotency key yang sama tidak mengurangi stok dua kali.
- QRIS tidak menggunakan payment gateway atau callback otomatis pada MVP.

## Perubahan file

- `app/Http/Controllers/Api/TransactionController.php`
- `app/Models/Transaction.php`
- `database/migrations/2026_11_03_000001_add_manual_qris_verification_to_transactions.php`
- `tests/Feature/TransactionCheckoutTest.php`
- `README.md`

## Pengujian yang ditambahkan

- QRIS ditolak tanpa konfirmasi manual.
- QRIS berhasil setelah konfirmasi manual dan mencatat Kasir, waktu, serta referensi.
- QRIS menolak nominal yang tidak sama dengan total.
- Retry QRIS dengan idempotency key yang sama tidak mengurangi stok dua kali.

## Keterbatasan yang diketahui

Stok belum direservasi saat pelanggan sedang membayar QRIS. Backend tetap mengunci dan memeriksa stok pada saat checkout disimpan. Jika stok habis pada jeda tersebut, checkout ditolak. Mekanisme reservasi stok dengan pembatalan/timeout dapat dipertimbangkan jika operasional toko membutuhkan penanganan transaksi tertunda.
