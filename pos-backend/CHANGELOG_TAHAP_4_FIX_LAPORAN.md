# Perbaikan Tahap 4 — Filter Tanggal Laporan Pembelian

## Temuan
Dua feature test laporan pembelian gagal karena query menggunakan `whereBetween()` dengan batas akhir berupa tanggal (`YYYY-MM-DD`). Pada SQLite, nilai tanggal yang ditulis melalui Eloquent date cast dapat tersimpan sebagai teks datetime (`YYYY-MM-DD 00:00:00`). Nilai pada hari terakhir kemudian lebih besar secara leksikografis daripada batas akhir `YYYY-MM-DD`, sehingga penerimaan pada tanggal akhir tidak terhitung.

## Perbaikan
Filter tanggal pembelian pada:
- Ringkasan Owner (`GET /api/reports/summary`)
- Laporan pembelian (`GET /api/reports/purchases`)
- Rekap pembelian per supplier

diubah menjadi rentang inklusif dengan batas bawah `>= tanggal awal` dan batas atas eksklusif `< hari setelah tanggal akhir`.

Pendekatan ini tetap mencakup seluruh transaksi pada tanggal akhir, mendukung nilai DATE maupun DATETIME, dan tidak membungkus kolom dengan fungsi `DATE()` sehingga tetap ramah indeks.

## Verifikasi
- `php -l` berhasil untuk 79 file PHP pada `app`, `routes`, `database`, dan `tests`.
- Logika batas tanggal diuji terpisah dengan SQLite: pola lama menghasilkan 0 record untuk nilai datetime pada hari akhir; pola baru menghasilkan 1 record.
- PHPUnit belum dijalankan pada lingkungan pembuat ZIP karena ekstensi PHP `dom`, `mbstring`, `xmlwriter`, dan `pdo_sqlite` tidak tersedia. Jalankan `php artisan test` pada lingkungan Laragon untuk verifikasi akhir.
