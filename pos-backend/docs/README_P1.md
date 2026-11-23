# P1 — Chatbot Publik LARIS - ABICELL

## Tujuan dan batasan

Patch ini melengkapi SRS v0.2.1 **FR-CHAT-01/02/03**, aturan **BR-12/13**, serta **NFR-10**:

- Halaman bantuan publik tanpa login. **Bukan e-commerce**, tidak ada checkout online.
- Chatbot **rule-based** dari konfigurasi FAQ statis, bukan AI generatif.
- Pertanyaan di luar aturan, ambigu, dan sensitif diberi jawaban aman + topik bantuan.
- API publik divalidasi dan di-*rate limit*, terpisah dari `assistant/chat` internal.

Tidak mengubah checkout/Kasbon P0, customer management, stok, dashboard, ataupun laporan.

## File yang ditambahkan / diubah

| Jenis | Path relatif dari folder `pos-abi/` | Fungsi |
|---|---|---|
| Ubah | `pos-backend/routes/api.php` | Route publik di luar `auth:sanctum` |
| Baru | `pos-backend/config/public_chatbot.php` | FAQ, pola keyword, privasi dan fallback |
| Baru | `pos-backend/app/Http/Controllers/Api/PublicChatController.php` | Handler FAQ dan chat tanpa query data internal |
| Baru | `pos-backend/tests/Feature/PublicChatbotTest.php` | 10 pengujian fitur/keamanan |
| Ubah | `pos-frontend/src/main.jsx` | Pilih UI publik tanpa memuat shell login |
| Ubah | `pos-frontend/src/pages/LoginPage.jsx` | Link Bantuan pelanggan |
| Baru | `pos-frontend/src/api/publicChat.js` | API client anonim; **tidak mengirim Bearer token** |
| Baru | `pos-frontend/src/pages/PublicHelpPage.jsx` | Halaman publik responsif |
| Baru | `pos-frontend/src/pages/PublicHelpPage.css` | CSS terpisah agar styling dashboard tidak terganggu |

## Cara memasang

1. **Backup / commit** kode yang sudah melewati test P0. Patch ini dibuat dari **baseline setelah P0**, bukan kode ZIP lama sebelum P0.
2. Salin isi folder `Chatbot_P1_Patch/pos-backend/` dan `Chatbot_P1_Patch/pos-frontend/` ke folder bersesuaian dalam project, pertahankan struktur path. **Jangan** mengganti satu folder project sekaligus.
3. Untuk developer yang memakai Git, alternatifnya jalankan `git apply --check Chatbot_P1_Perubahan.patch` lalu `git apply Chatbot_P1_Perubahan.patch` dari root folder yang memiliki `pos-backend/` dan `pos-frontend/`. Jika temanmu sudah mengedit `main.jsx`, `LoginPage.jsx`, atau `api.php`, lakukan merge manual agar perubahan mereka tidak tertimpa.
4. Backend (dalam `pos-backend/`):

   ```bash
   php artisan optimize:clear
   php artisan route:list --path=public
   php artisan test --filter=PublicChatbotTest
   php artisan test --filter=CustomerDebtPaymentTest
   ```

5. Frontend (dalam `pos-frontend/`):

   ```bash
   npm run build
   npm run dev
   ```

6. Buka **`http://localhost:5173/?halaman=bantuan`**. Dari halaman login juga ada tautan **Bantuan pelanggan (tanpa login)**.

   `http://localhost:5173/bantuan` juga berjalan pada Vite development server. Untuk production, path `/bantuan` memerlukan pengaturan fallback SPA ke `index.html` di server; URL `/?halaman=bantuan` tidak memerlukan fallback tambahan.

7. Coba pertanyaan: `Bagaimana cara belanja di toko?`, `Metode pembayaran apa yang tersedia?`, `Jam buka dan metode pembayaran apa yang tersedia?`, `Berapa margin toko?`, dan pertanyaan di luar FAQ.

## Kontrak API

- `GET /api/public/faq` — tanpa auth, dibatasi 60 request per menit/IP.
- `POST /api/public/chat` — tanpa auth, dibatasi 12 request per menit/IP.

  Contoh request:

  ```json
  {"question":"Bagaimana cara belanja di toko?"}
  ```

  Contoh respons (ringkas):

  ```json
  {"status":"success","data":{"intent":"belanja","answer":"...","fallback":false,"topics":[{"id":"belanja","label":"Cara belanja","question":"..."}]}}
  ```

  Input `question` wajib string, tidak kosong, maksimal 300 karakter. Status `422` untuk validasi dan `429` untuk rate limit.

## Validasi konten oleh Owner — wajib sebelum publikasi nyata

SRS menyatakan daftar FAQ dan informasi publik perlu divalidasi Owner. `config/public_chatbot.php` berisi jawaban dasar yang hanya menggunakan informasi yang telah tampak dalam SRS/kode (misalnya tidak ada checkout online, metode tunai/QRIS, jenis layanan digital). **Jam buka, alamat, nomor kontak, serta angka stok tidak ditebak**. Pastikan setiap jawaban disetujui Owner. Jika perlu mengganti teks/pola aturan, edit `config/public_chatbot.php`, kemudian `php artisan config:clear` atau refresh config cache di deployment.

Fitur **FR-CHAT-04** (Owner mengedit FAQ dari UI) dan **FR-CHAT-05** (status umum ketersediaan stok secara real-time) berstatus **Should**, sengaja tidak diimplementasikan pada P1 ini agar tidak mengekspos stok/internal tanpa izin.

## Catatan keamanan / deploy

- Jangan memindahkan route `assistant/chat` dari grup autentikasi.
- Jangan menggunakan `src/api/client.js` untuk halaman publik; modul itu mengirim token jika ada.
- Jangan mempublikasikan `.env`/database atau backend vendor/node_modules dalam patch.
- Endpoint internal `customers`, `reports`, dan `assistant/chat` tetap dilindungi `auth:sanctum`.
- Di production: HTTPS, konfigurasi URL API dan CORS bila beda origin, cache/rate limiter persisten bila multi-instance, serta approval FAQ oleh Owner.

## Pemeriksaan yang dilakukan oleh pembuat patch

- Lulus `php -l` untuk seluruh PHP baru/diubah.
- Lulus parsing JSX/JS oleh Babel parser.
- `php artisan route:list --path=public` mendeteksi 2 route publik.
- HTTP smoke test berhasil untuk FAQ, jawaban dikenal, ambigu, privasi, fallback, `422` validasi, `401` API internal, dan `429` throttle setelah 12 request.
- **PHPUnit penuh belum dapat dijalankan dalam lingkungan pembuatan patch** karena ekstensi PHP `dom`, `mbstring`, dan `xmlwriter` tidak tersedia di environment tersebut. Jalankan `PublicChatbotTest` di Laragon; tidak ada migration baru.
