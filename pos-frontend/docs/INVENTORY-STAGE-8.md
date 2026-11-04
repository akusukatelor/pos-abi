# Tahap 8 — Inventory Management

## Ruang lingkup frontend

Halaman Inventory mengintegrasikan endpoint Laravel yang sudah tersedia untuk:

- daftar persediaan dan ringkasan stok;
- draft penerimaan supplier dan konfirmasi penerimaan;
- pencatatan stock opname oleh Owner/Kasir;
- persetujuan atau penolakan stock opname oleh Owner;
- riwayat pergerakan stok;
- rekomendasi restok khusus Owner.

Tidak ada migration atau perubahan backend pada tahap ini. Hak akses tetap ditegakkan oleh middleware dan controller Laravel, bukan hanya oleh tombol frontend.

## Aturan operasional

1. Draft penerimaan tidak mengubah stok.
2. Penerimaan menambah stok hanya setelah petugas menyatakan barang benar-benar diterima dan supplier sudah dibayar.
3. Owner dan Kasir boleh mencatat serta mengonfirmasi penerimaan sesuai keputusan operasional Toko Abi.
4. Owner dan Kasir boleh mencatat hasil stock opname.
5. Stock opname berstatus `pending_approval` tidak mengubah stok.
6. Hanya Owner yang dapat menyetujui atau menolak penyesuaian stock opname.
7. Persetujuan stock opname memeriksa ulang stok sistem. Jika stok berubah setelah hitung fisik, backend menolak persetujuan dan meminta penghitungan ulang.
8. Setiap perubahan stok tetap dicatat di `stock_movements`.
9. Rekomendasi restok hanya informasi untuk keputusan Owner; tidak membuat pesanan, tidak mengubah stok, dan tidak menjalankan pembelian otomatis.

## Endpoint yang digunakan

| Method | Endpoint | Tujuan |
|---|---|---|
| GET | `/api/products` | Daftar produk dan stok |
| GET | `/api/reports/inventory` | Ringkasan persediaan Owner |
| GET | `/api/suppliers` | Pilihan supplier |
| POST | `/api/suppliers` | Tambah supplier (Owner) |
| GET | `/api/purchase-receipts` | Riwayat penerimaan |
| POST | `/api/purchase-receipts` | Buat draft penerimaan |
| POST | `/api/purchase-receipts/{id}/confirm` | Konfirmasi barang diterima dan dibayar |
| DELETE | `/api/purchase-receipts/{id}` | Hapus draft yang belum dikonfirmasi sesuai izin backend |
| GET | `/api/stock-opnames` | Daftar stock opname |
| POST | `/api/stock-opnames` | Simpan hasil hitung fisik |
| GET | `/api/stock-opnames/{id}` | Detail stock opname |
| POST | `/api/stock-opnames/{id}/approve` | Persetujuan Owner |
| POST | `/api/stock-opnames/{id}/reject` | Penolakan Owner |
| GET | `/api/stock-movements` | Riwayat pergerakan stok |
| GET | `/api/restock-recommendations` | Rekomendasi restok Owner |

## Checklist pengujian manual

### Persediaan

- [ ] Owner melihat ringkasan produk aktif, stok kosong, dan stok negatif dari backend.
- [ ] Kasir hanya melihat informasi produk dan stok yang diizinkan.
- [ ] Harga beli tidak muncul pada akun Kasir.
- [ ] Pencarian global dan filter kategori bekerja pada produk yang dimuat.
- [ ] Navigasi halaman produk bekerja bila jumlah produk lebih dari 50.

### Penerimaan supplier

- [ ] Owner dapat membuat draft penerimaan.
- [ ] Kasir dapat membuat draft penerimaan.
- [ ] Supplier, tanggal, nota, produk, kuantitas, dan harga beli tervalidasi.
- [ ] Produk yang sama tidak dapat dimasukkan dua kali pada satu dokumen.
- [ ] Draft tidak mengubah stok.
- [ ] Konfirmasi membutuhkan acknowledgement bahwa barang diterima dan pembayaran dilakukan.
- [ ] Setelah konfirmasi, stok bertambah sesuai kuantitas satu kali.
- [ ] Stock movement menyimpan user, supplier, referensi item penerimaan, tipe `in`, dan kuantitas.
- [ ] Draft dapat dihapus sesuai otorisasi; penerimaan terkonfirmasi tidak dapat dihapus.

### Stock opname

- [ ] Owner dan Kasir dapat menyimpan hasil hitung fisik.
- [ ] Produk duplikat dalam satu dokumen ditolak.
- [ ] Stok fisik harus bilangan bulat nol atau lebih.
- [ ] Selisih membutuhkan alasan pada item atau catatan umum.
- [ ] Saat disimpan, status menjadi `pending_approval` dan stok belum berubah.
- [ ] Kasir tidak dapat menyetujui atau menolak.
- [ ] Owner dapat menyetujui selisih positif dan negatif.
- [ ] Owner dapat menolak dengan alasan; stok tetap.
- [ ] Persetujuan ulang tidak menggandakan stock movement.
- [ ] Jika stok berubah setelah snapshot, persetujuan ditolak dan pengguna diminta hitung ulang.

### Riwayat dan rekomendasi

- [ ] Riwayat stok menampilkan arah masuk/keluar, jumlah, petugas, supplier, dan keterangan.
- [ ] Rekomendasi hanya dapat dibuka Owner.
- [ ] Produk dengan data/parameter tidak cukup ditampilkan sebagai `insufficient_data`, tanpa jumlah usulan palsu.
- [ ] Rekomendasi tidak mengubah stok atau membuat transaksi pembelian.

## Batasan yang diketahui

- Endpoint daftar produk mengembalikan 50 data per halaman; dropdown penerimaan dan opname menggunakan produk yang sedang dimuat pada halaman persediaan.
- Daftar penerimaan dan stock opname mengikuti batas pagination backend (25 terbaru); riwayat pergerakan mengikuti 50 terbaru.
- Konversi satuan (misalnya dus ke botol atau kilogram ke gram) belum tersedia pada model produk saat ini. Kuantitas harus dicatat dalam satuan produk yang telah ditetapkan.
- Penyesuaian langsung melalui endpoint stock movement tidak ditampilkan sebagai tombol cepat pada UI tahap ini karena endpoint tersebut belum memiliki idempotency key. Koreksi stok diarahkan melalui stock opname dengan persetujuan Owner agar pengiriman ulang permintaan tidak menggandakan penyesuaian.
- Rekomendasi numerik memerlukan parameter restok yang diatur Owner pada menu Settings atau data penjualan berbayar yang cukup sesuai aturan backend.
