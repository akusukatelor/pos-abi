# Tahap 6 — Harga Aktual Penerimaan dan Harga Beli Master

- Owner dan Kasir mengisi `unit_cost` sesuai harga aktual pada nota supplier.
- Harga aktual disimpan pada `purchase_receipt_items` dan tidak mengubah `products.buy_price`.
- Endpoint katalog produk menyediakan `buy_price` untuk Kasir hanya jika query `for_receiving=1` digunakan pada Inventory.
- Endpoint katalog POS biasa tetap menyembunyikan `buy_price` dari Kasir.
- Perubahan `products.buy_price` hanya tersedia melalui endpoint produk Owner-only yang sudah dilindungi middleware role.
- Perubahan harga master mewajibkan alasan dan dicatat pada `product_price_histories` bersama user, harga lama, harga baru, dan waktu.
- Riwayat harga master hanya dapat dibaca Owner.

## Upgrade

```bash
php artisan migrate
php artisan test
```
