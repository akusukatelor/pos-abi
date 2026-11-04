# Checklist Uji Manual Frontend POS LARIS - ABICELL

Lakukan pengujian menggunakan data uji/staging terlebih dahulu. Jangan memakai transaksi uji pada data operasional tanpa izin Owner.

## Persiapan

- [ ] Backend Laravel berjalan pada `http://127.0.0.1:8000`.
- [ ] Database sudah dimigrasikan sampai Tahap 5.
- [ ] Akun Owner dan Kasir aktif tersedia.
- [ ] Produk aktif memiliki kategori, satuan, harga jual, dan stok.
- [ ] Jenis layanan digital yang aktif tersedia.

## Login dan hak akses

- [ ] Login Owner berhasil dan membuka Dashboard.
- [ ] Login Kasir berhasil dan membuka POS.
- [ ] Akun tidak aktif atau password salah ditolak.
- [ ] Kasir tidak melihat menu Dashboard, Reports, dan Settings.
- [ ] Kasir tidak dapat membuka endpoint Owner-only melalui UI/API.
- [ ] Logout menghapus token dari browser.

## Dashboard Owner

- [ ] Total penjualan sesuai transaksi berstatus paid pada rentang tanggal.
- [ ] Total transaksi sesuai jumlah transaksi barang yang lunas.
- [ ] Margin layanan hanya menjumlah margin yang telah diverifikasi Owner.
- [ ] Stok perlu ditinjau sesuai rekomendasi restok.
- [ ] Grafik metode pembayaran memisahkan Tunai dan QRIS GoPay.
- [ ] Grafik tren menampilkan omzet barang, bukan nominal pokok PPOB/BRILink.
- [ ] Tabel transaksi terbaru menampilkan transaksi barang dan layanan.
- [ ] Filter tanggal awal/akhir bekerja dan menolak rentang tanggal terbalik.
- [ ] Kondisi tanpa data menampilkan empty state, bukan angka contoh.

## POS barang

- [ ] Produk dan kategori dimuat dari API.
- [ ] Pencarian produk dan filter kategori bekerja.
- [ ] Produk stok nol tidak dapat ditambahkan.
- [ ] Kuantitas keranjang tidak dapat melebihi stok tersedia.
- [ ] Total keranjang sesuai harga jual database.
- [ ] Pembayaran tunai di bawah total ditolak.
- [ ] Pembayaran tunai berhasil menampilkan struk dan kembalian.
- [ ] Stok berkurang sesuai kuantitas hanya satu kali.
- [ ] Request checkout yang diulang dengan idempotency key yang sama tidak menggandakan transaksi.
- [ ] Struk dapat dicetak dari browser.

## QRIS GoPay Merchant

- [ ] Tombol konfirmasi tidak aktif sebelum Kasir mencentang verifikasi manual.
- [ ] Kasir memeriksa pembayaran pada aplikasi GoPay Merchant yang sah.
- [ ] Nominal QRIS sama persis dengan total transaksi.
- [ ] Referensi pembayaran dapat disimpan jika tersedia.
- [ ] Riwayat mencatat Kasir dan waktu verifikasi QRIS.
- [ ] UI tidak mengklaim melakukan verifikasi otomatis.

## Layanan digital

- [ ] Hanya jenis layanan aktif yang muncul pada katalog POS.
- [ ] Transaksi layanan dicatat setelah berhasil diproses pada aplikasi penyedia.
- [ ] Nominal pokok boleh kosong dan tidak diperlakukan sebagai omzet.
- [ ] Margin boleh kosong; margin kosong tidak dianggap nol.
- [ ] Pencatatan layanan tidak mengurangi stok barang.
- [ ] Owner dapat memverifikasi margin.
- [ ] Perubahan margin terverifikasi mewajibkan catatan sesuai aturan backend.

## Inventory, supplier, laporan, dan pengaturan

- [ ] Inventory menampilkan stok terbaru dari backend.
- [ ] Kasir dapat melihat inventory tanpa memanggil endpoint laporan Owner-only.
- [ ] Supplier ditampilkan dari database.
- [ ] Laporan memisahkan omzet barang, pembelian, dan margin jasa.
- [ ] Laporan tidak menampilkan laba kotor sebelum metode HPP disepakati.
- [ ] Parameter restok hanya dapat diubah Owner.
- [ ] Rekomendasi restok tidak memicu pembelian atau perubahan stok otomatis.

## Tampilan dan responsivitas

- [ ] Sidebar, header, kartu KPI, grafik, dan tabel sesuai desain referensi.
- [ ] POS menampilkan katalog di kiri dan keranjang/pembayaran di kanan pada desktop.
- [ ] Layout berubah menjadi satu kolom pada layar kecil.
- [ ] Modal dan tabel tetap dapat digunakan pada layar laptop dan ponsel.
- [ ] Loading, error, dan empty state terlihat jelas.


## Cetak struk pada printer berbeda

- [ ] Buka dialog cetak dari transaksi yang berhasil.
- [ ] Pilih printer besar dan pastikan ukuran kertas dipilih dari pengaturan printer/browser.
- [ ] Pastikan hanya area struk yang tercetak; sidebar, dashboard, modal, dan tombol tidak ikut tercetak.
- [ ] Ulangi dengan printer kecil dan ukuran kertas yang tersedia pada printer tersebut.
- [ ] Pastikan lebar struk menyesuaikan area kertas tanpa terpotong ke samping.
- [ ] Pastikan margin halaman dan padding struk tetap nyaman dibaca.
- [ ] Transaksi pendek tercetak satu halaman.
- [ ] Transaksi panjang boleh berlanjut ke halaman berikutnya tanpa memotong baris item atau total.
- [ ] Pastikan tidak ada halaman kosong tambahan.
- [ ] Coba cetak ulang dari struk yang sama dan pastikan tidak membuat transaksi baru.

Catatan: CSS tidak memaksa ukuran A4, Letter, atau ukuran thermal. Pilih ukuran kertas yang benar pada dialog print/driver printer.

## Inventory — Tahap 8

- [ ] Owner dapat melihat ringkasan persediaan dan harga beli; Kasir tidak melihat harga beli.
- [ ] Daftar produk mendukung pencarian, filter kategori, kondisi stok, dan pagination.
- [ ] Owner dapat menambahkan supplier dari form penerimaan; Kasir hanya memilih supplier yang tersedia.
- [ ] Owner dan Kasir dapat membuat draft penerimaan supplier.
- [ ] Draft penerimaan tidak menambah stok.
- [ ] Konfirmasi penerimaan memerlukan pernyataan bahwa barang sudah diterima dan supplier sudah dibayar.
- [ ] Konfirmasi penerimaan menambah stok satu kali dan membuat stock movement.
- [ ] Owner dan Kasir dapat mencatat hasil stock opname.
- [ ] Hasil opname berstatus menunggu Owner dan belum mengubah stok.
- [ ] Kasir tidak dapat menyetujui atau menolak stock opname.
- [ ] Owner dapat menyetujui atau menolak opname dengan alasan.
- [ ] Opname yang stale ditolak backend jika stok berubah setelah penghitungan.
- [ ] Riwayat pergerakan stok menampilkan tipe, jumlah, petugas, supplier, dan keterangan.
- [ ] Rekomendasi restok hanya dapat diakses Owner dan tidak mengubah stok atau membuat pesanan.


## Harga beli aktual dan harga beli master

- [ ] Owner dan Kasir melihat kolom Harga beli aktual / unit pada form penerimaan.
- [ ] Harga master terisi otomatis saat produk dipilih pada form penerimaan untuk kedua role.
- [ ] Owner dan Kasir dapat mengubah harga aktual sesuai nota supplier.
- [ ] Harga aktual tersimpan pada detail penerimaan dan tidak mengubah `products.buy_price`.
- [ ] Kasir tidak dapat mengubah harga beli master melalui UI maupun API.
- [ ] Owner dapat mengubah harga beli master dari daftar persediaan.
- [ ] Perubahan harga master mewajibkan alasan.
- [ ] Perubahan harga master tercatat dengan harga lama, harga baru, user, waktu, dan alasan.
- [ ] Riwayat harga master hanya dapat dibuka oleh Owner.
- [ ] Harga aktual pada penerimaan lama tidak berubah setelah harga master diperbarui.
