<?php

/**
 * Konten chatbot publik LARIS - ABICELL.
 *
 * Hanya masukkan informasi yang telah disetujui Owner untuk dipublikasikan.
 * Jam operasional, alamat, nomor telepon, serta harga/stok produk belum
 * tersedia dalam baseline SRS; jangan mengisinya dengan tebakan.
 *
 * Untuk memperbarui FAQ, ubah konfigurasi ini lalu jalankan:
 * php artisan config:clear (atau rebuild config cache ketika deploy).
 */
return [
    'fallback' => 'Maaf, saya belum memiliki informasi yang pasti untuk pertanyaan tersebut. Pilih salah satu topik di bawah atau tanyakan langsung kepada petugas toko.',
    'ambiguous' => 'Pertanyaan ini menyangkut lebih dari satu topik. Agar tidak memberikan jawaban yang keliru, silakan pilih satu topik di bawah atau ajukan satu pertanyaan yang lebih spesifik.',
    'private_answer' => 'Maaf, data pribadi pelanggan dan informasi internal toko tidak dapat ditampilkan melalui layanan publik ini. Silakan menghubungi petugas toko untuk bantuan sesuai prosedur verifikasi.',

    // Pemeriksaan ini harus didahulukan sebelum pencocokan topik biasa.
    // Terutama untuk mencegah pertanyaan yang menggabungkan FAQ publik
    // dengan permintaan data internal menerima jawaban yang menyesatkan.
    'private_patterns' => [
        '/\b(?:margin|laba|profit|keuntungan|harga beli|harga modal|harga pokok|modal barang|data supplier)\b/u',
        '/\b(?:riwayat transaksi|transaksi saya|riwayat pembelian|data pelanggan|nomor pelanggan|data customer|kasbon|piutang|hutang|utang|saldo saya)\b/u',
        '/\b(?:jumlah stok|angka stok|berapa stok|stok berapa|stok internal|stok persis|kuantitas stok|stok gudang)\b/u',
    ],

    // Semua jawaban ini berupa teks statis yang aman untuk publik.
    // Topik 'jam', 'lokasi', dan 'kontak' sengaja tidak mengarang detail toko.
    'intents' => [
        'belanja' => [
            'label' => 'Cara belanja',
            'question' => 'Bagaimana cara belanja di toko?',
            'answer' => 'Saat ini pembelian dilakukan langsung di toko. Website ini hanya menyediakan informasi dan bantuan; pemesanan serta checkout online belum tersedia.',
            'patterns' => [
                '/\b(?:cara belanja|cara membeli|cara beli|belanja online|pesan online|checkout online|pemesanan online|bisa pesan|bisa beli online)\b/u',
            ],
        ],
        'pembayaran' => [
            'label' => 'Pembayaran',
            'question' => 'Metode pembayaran apa yang tersedia?',
            'answer' => 'Transaksi di kasir mendukung pembayaran tunai dan QRIS. Untuk memastikan ketentuan pembayaran yang berlaku saat berbelanja, silakan tanyakan langsung kepada petugas toko.',
            'patterns' => [
                '/\b(?:metode pembayaran|cara pembayaran|pembayaran apa|bayar tunai|bayar qris|pakai qris|terima qris|bayar pakai apa)\b/u',
            ],
        ],
        'layanan' => [
            'label' => 'Layanan digital',
            'question' => 'Apa saja layanan digital di toko?',
            'answer' => 'Toko memiliki layanan digital seperti PPOB dan BRILink. Jenis layanan yang sedang tersedia serta ketentuannya dapat dikonfirmasi langsung kepada petugas toko.',
            'patterns' => [
                '/\b(?:layanan digital|jasa digital|ppob|brilink|pembayaran tagihan|bayar listrik|token listrik|isi pulsa|beli pulsa)\b/u',
            ],
        ],
        'ketersediaan' => [
            'label' => 'Ketersediaan barang',
            'question' => 'Bagaimana cara mengecek ketersediaan barang?',
            'answer' => 'Untuk memastikan barang tertentu tersedia, silakan tanyakan langsung kepada petugas toko. Layanan publik ini tidak menampilkan angka stok internal atau memastikan stok produk secara otomatis.',
            'patterns' => [
                '/\b(?:ketersediaan barang|ketersediaan produk|cek ketersediaan|cek stok|barang tersedia|produk tersedia|barang ready|produk ready)\b/u',
            ],
        ],
        'jam' => [
            'label' => 'Jam operasional',
            'question' => 'Jam berapa toko buka?',
            'answer' => 'Jam operasional belum dipublikasikan melalui layanan ini. Silakan konfirmasi jam buka dan tutup kepada petugas toko.',
            'patterns' => [
                '/\b(?:jam buka|jam tutup|jam operasional|buka jam|tutup jam|kapan buka|hari buka)\b/u',
            ],
        ],
        'lokasi' => [
            'label' => 'Lokasi toko',
            'question' => 'Di mana lokasi toko?',
            'answer' => 'Alamat lengkap toko belum dipublikasikan melalui layanan ini. Silakan meminta petunjuk lokasi kepada petugas toko.',
            'patterns' => [
                '/\b(?:alamat toko|lokasi toko|di mana lokasi|dimana lokasi|di mana toko|dimana toko|arah ke toko)\b/u',
            ],
        ],
        'kontak' => [
            'label' => 'Kontak toko',
            'question' => 'Bagaimana cara menghubungi toko?',
            'answer' => 'Nomor kontak resmi belum dipublikasikan melalui layanan ini. Silakan hubungi petugas secara langsung di toko.',
            'patterns' => [
                '/\b(?:kontak toko|nomor toko|nomor telepon toko|nomor wa toko|whatsapp toko|hubungi toko|menghubungi toko)\b/u',
            ],
        ],
    ],
];
