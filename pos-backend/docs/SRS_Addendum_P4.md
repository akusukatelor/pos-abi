# Addendum untuk SRS — P4 E-Commerce & Customer Loyalty

**Status: Usulan perubahan requirement, MENUNGGU persetujuan Owner dan dosen pembimbing.**

SRS v0.2.1 menempatkan e-commerce di luar cakupan. Arahan dosen terbaru mewajibkannya. Dokumen ini adalah addendum implementasi agar jejak perubahan jelas; tidak mengubah SRS resmi secara diam-diam.

| ID usulan | Requirement | Prioritas implementasi P4 |
|---|---|---|
| FR-WEB-01 | Pelanggan dapat daftar/login/logout dengan akun berbeda dari staf. | Implemented |
| FR-WEB-02 | Katalog publik menampilkan produk aktif, harga jual, dan ketersediaan umum tanpa membocorkan data internal. | Implemented |
| FR-WEB-03 | Pelanggan login dapat membuat pesanan dan melihat riwayat miliknya; pesanan awal belum lunas. | Implemented |
| FR-WEB-04 | Owner dapat menyelesaikan pesanan setelah mengonfirmasi tunai diterima dan barang diserahkan, dengan update stok dan transaksi atomik. | Implemented |
| FR-WEB-05 | Owner dapat membatalkan pending order dengan alasan. | Implemented |
| FR-WEB-06 | Akun customer bisa melihat saldo/riwayat loyalty yang dapat diaudit. | Fondasi read-only |
| FR-WEB-07 | Poin diberi/ditukar sesuai ketentuan bisnis yang disahkan. | **Pending aturan klien, sengaja belum aktif** |
| FR-WEB-08 | Pengiriman, payment gateway, COD/QRIS online, verifikasi email/reset password. | Di luar P4 MVP; perlu keputusan berikutnya |

**Risiko dan keputusan terbuka:** minimum belanja, skema poin, masa berlaku, level loyalitas, kapan order dianggap selesai, pickup SLA, kontak toko, penggabungan akun offline/online, biaya layanan, stok reservasi, pembatalan setelah pembayaran, dan refund.

**Kriteria penerimaan awal:** registrasi/login customer berhasil; customer token tidak mengakses POS; order pending tidak mengubah stok; owner complete membuat satu transaksi dan satu kali pengurangan stok; data order terisolasi per customer; poin tidak dikarang sebelum aturan disepakati.
