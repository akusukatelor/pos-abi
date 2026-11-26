<?php

/**
 * Konten chatbot publik LARIS - ABICELL.
 * Setelah mengubah file ini, jalankan: php artisan config:clear
 */
return [
    'fallback' => 'Maaf, saya belum memiliki informasi spesifik untuk pertanyaan tersebut. Silakan pilih salah satu topik di bawah atau tanyakan nama produk untuk mengecek ketersediaannya.',
    'ambiguous' => 'Pertanyaan ini mencakup lebih dari satu topik. Silakan pilih satu topik di bawah agar jawaban lebih tepat.',
    'private_answer' => 'Maaf, data pribadi pelanggan, rincian kasbon, harga modal, maupun angka stok internal tidak dapat diakses melalui layanan publik ini. Silakan hubungi petugas toko secara langsung.',

    'private_patterns' => [
        '/\b(?:margin|laba|profit|keuntungan|harga beli|harga modal|harga pokok|modal barang|data supplier|supplier)\b/u',
        '/\b(?:riwayat transaksi|transaksi saya|riwayat pembelian|data pelanggan|nomor pelanggan|data customer|kasbon|piutang|hutang|utang|saldo saya)\b/u',
        '/\b(?:jumlah stok|angka stok|berapa stok|stok berapa|stok internal|stok persis|kuantitas stok|stok gudang)\b/u',
    ],

    'intents' => [
        'belanja' => [
            'label' => 'Cara belanja online',
            'question' => 'Bagaimana cara belanja di toko online?',
            'answer' => 'Pelanggan dapat mendaftar atau login pada halaman Toko Online, memilih produk ke keranjang, lalu membuat pesanan. Pembayaran dapat dilakukan secara tunai saat pengambilan barang di toko maupun menggunakan QRIS GoPay.',
            'patterns' => [
                '/\b(?:cara belanja|cara membeli|cara beli|belanja online|pesan online|checkout online|pemesanan online|bisa pesan|bisa beli online)\b/u',
            ],
        ],
        'pembayaran' => [
            'label' => 'Metode pembayaran',
            'question' => 'Metode pembayaran apa saja yang tersedia?',
            'answer' => 'LARIS - ABICELL mendukung pembayaran Tunai dan QRIS (termasuk QRIS GoPay) baik untuk transaksi langsung di kasir maupun pesanan online.',
            'patterns' => [
                '/\b(?:metode pembayaran|cara pembayaran|pembayaran apa|bayar tunai|bayar qris|pakai qris|terima qris|bayar pakai apa|gopay)\b/u',
            ],
        ],
        'layanan' => [
            'label' => 'Layanan PPOB & BRILink',
            'question' => 'Apa saja layanan digital yang tersedia?',
            'answer' => 'Toko melayani transaksi digital meliputi Transfer & Tarik Tunai BRILink, Token Listrik PLN, Pulsa & Paket Data, Top Up E-Wallet (DANA, GoPay, OVO, ShopeePay), serta pembayaran tagihan PDAM dan BPJS.',
            'patterns' => [
                '/\b(?:layanan digital|jasa digital|ppob|brilink|pembayaran tagihan|bayar listrik|token listrik|isi pulsa|beli pulsa|top up)\b/u',
            ],
        ],
        'loyalty' => [
            'label' => 'Poin loyalitas',
            'question' => 'Bagaimana cara mendapatkan dan menukar poin loyalitas?',
            'answer' => 'Pelanggan terdaftar otomatis mengumpulkan poin loyalitas setiap kali melakukan transaksi belanja. Poin yang terkumpul dapat dicek di akun Toko Online dan ditukarkan saat berbelanja di toko sesuai ketentuan yang berlaku.',
            'patterns' => [
                '/\b(?:poin|point|loyalty|loyalitas|tukar poin|reward)\b/u',
            ],
        ],
        'ketersediaan' => [
            'label' => 'Cek ketersediaan barang',
            'question' => 'Bagaimana cara mengecek ketersediaan barang?',
            'answer' => 'Anda dapat mengetik langsung nama produk di chat ini (contoh: "Apakah beras tersedia?" atau "Harga minyak goreng") atau melihat status ketersediaannya secara langsung di halaman Toko Online.',
            'patterns' => [
                '/\b(?:ketersediaan barang|ketersediaan produk|cek ketersediaan|cek stok|barang tersedia|produk tersedia|barang ready|produk ready)\b/u',
            ],
        ],
        'jam' => [
            'label' => 'Jam operasional',
            'question' => 'Jam berapa toko buka?',
            'answer' => 'Toko beroperasi setiap hari mulai pukul 07.00 hingga 21.00 WIB. Untuk pesanan online di luar jam tersebut akan diproses pada jam buka berikutnya.',
            'patterns' => [
                '/\b(?:jam buka|jam tutup|jam operasional|buka jam|tutup jam|kapan buka|hari buka)\b/u',
            ],
        ],
        'lokasi' => [
            'label' => 'Lokasi & kontak toko',
            'question' => 'Di mana lokasi dan kontak toko?',
            'answer' => 'Toko LARIS - ABICELL berlokasi di area Purwokerto, Banyumas. Pengambilan pesanan online dilakukan langsung di gerai toko dengan menunjukkan kode pesanan Anda.',
            'patterns' => [
                '/\b(?:alamat toko|lokasi toko|di mana lokasi|dimana lokasi|di mana toko|dimana toko|arah ke toko|kontak toko|nomor toko|whatsapp toko|hubungi toko)\b/u',
            ],
        ],
    ],
];