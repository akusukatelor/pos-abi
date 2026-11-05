# API Contract — Jasa Digital dan Laporan Owner (Tahap 4)

Base path: `/api`

Semua endpoint internal memerlukan bearer token Sanctum dan akun aktif. Nilai role database `admin` ditampilkan sebagai **Owner**, sedangkan `kasir` sebagai **Kasir**.

## Jenis layanan

| Method | Endpoint | Akses | Keterangan |
|---|---|---|---|
| GET | `/service-types` | Owner, Kasir | Daftar jenis layanan; Kasir hanya melihat yang aktif |
| GET | `/service-types/{id}` | Owner, Kasir | Detail jenis layanan |
| POST | `/service-types` | Owner | Membuat jenis layanan |
| PUT/PATCH | `/service-types/{id}` | Owner | Mengubah nama, deskripsi, atau status aktif |
| DELETE | `/service-types/{id}` | Owner | Menonaktifkan; tidak menghapus histori |

Contoh pembuatan:

```json
{
  "name": "PPOB",
  "description": "Pembayaran tagihan dan top up"
}
```

## Transaksi jasa

| Method | Endpoint | Akses | Keterangan |
|---|---|---|---|
| GET | `/service-transactions` | Owner, Kasir | Riwayat; Kasir hanya melihat transaksi miliknya |
| GET | `/service-transactions/{id}` | Owner, Kasir | Detail dengan pembatasan kepemilikan |
| POST | `/service-transactions` | Owner, Kasir | Mencatat layanan yang berhasil |
| POST | `/service-transactions/{id}/verify-margin` | Owner | Verifikasi/perubahan margin |
| POST | `/service-transactions/{id}/cancel` | Owner | Pembatalan administratif dengan alasan |

Contoh pencatatan jasa:

```json
{
  "idempotency_key": "UUID",
  "service_type_id": 1,
  "reference_number": "REF-OPTIONAL",
  "nominal_amount": 100000,
  "margin_amount": null,
  "notes": "Transaksi berhasil"
}
```

`nominal_amount` adalah nominal pokok layanan, bukan pendapatan toko. `margin_amount: null` berarti margin belum diketahui. Nilai `0` berarti margin telah diketahui nol, tetapi margin baru masuk laporan setelah diverifikasi Owner.

Contoh verifikasi margin:

```json
{
  "margin_amount": 2500,
  "note": "Berdasarkan bukti komisi"
}
```

Jika margin yang sudah diverifikasi diubah, `note` wajib diisi. Setiap verifikasi/perubahan disimpan pada `service_margin_logs`.

Contoh pembatalan:

```json
{
  "cancellation_reason": "Salah pencatatan transaksi"
}
```

Transaksi yang dibatalkan tidak dihitung pada laporan jasa aktif. Data dan alasan pembatalannya tetap disimpan.

## Laporan Owner

Semua endpoint berikut hanya dapat diakses Owner. Parameter periode opsional: `from=YYYY-MM-DD` dan `to=YYYY-MM-DD`. Jika tidak diisi, periode default adalah awal bulan berjalan sampai hari ini.

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/reports/summary` | Ringkasan omzet, pembelian, margin jasa, dan jumlah produk aktif/habis |
| GET | `/reports/sales` | Omzet barang dan rincian berdasarkan metode pembayaran |
| GET | `/reports/purchases` | Penerimaan supplier terkonfirmasi, termasuk ringkasan per supplier |
| GET | `/reports/inventory` | Kondisi stok aktif; filter `search` dan `zero_stock_only` |
| GET | `/reports/services` | Margin jasa terverifikasi dan transaksi yang marginnya belum diketahui |

### Batas interpretasi laporan

- Omzet barang adalah total transaksi penjualan barang berstatus `paid`.
- Total pembelian hanya menghitung penerimaan supplier berstatus `confirmed`.
- Margin jasa hanya menjumlahkan transaksi selesai yang sudah diverifikasi Owner.
- Nominal pokok PPOB/BRILink tidak dihitung sebagai pendapatan.
- Laba kotor belum dihitung karena metode HPP dan snapshot harga pokok historis belum ditetapkan.
- Laporan ini bukan laporan laba bersih seluruh usaha.
