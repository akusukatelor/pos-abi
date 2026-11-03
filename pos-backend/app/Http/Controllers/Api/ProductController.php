<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Tampilkan daftar produk
     * Menggunakan Eager Loading (with) untuk mengambil data relasi Category dan Unit
     */
    public function index(Request $request)
    {
        // Fitur tambahan: Pencarian (Search) berdasarkan nama atau SKU
        // Ini akan sangat berguna untuk Frontend React nanti
        $query = Product::with(['category', 'unit']);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('sku_code', 'LIKE', "%{$search}%");
        }

        // Mengambil data, bisa gunakan get() untuk semua, atau paginate() jika data banyak
        $products = $query->latest()->get();

        return response()->json([
            'status' => 'success',
            'data' => $products,
        ], 200);
    }

    public function restock(): \Illuminate\Http\JsonResponse
    {
        $products = Product::with(['category', 'unit'])->get()->map(function (Product $product) {
            $minimumStock = max((int) ($product->minimum_stock ?? 5), 0);
            $stock = (int) $product->stock;

            if ($stock >= $minimumStock) {
                return null;
            }

            return [
                'id' => $product->id,
                'sku_code' => $product->sku_code,
                'name' => $product->name,
                'stock' => $stock,
                'minimum_stock' => $minimumStock,
                'recommended_quantity' => max($minimumStock - $stock, 1),
                'category' => $product->category,
                'unit' => $product->unit,
            ];
        })->filter()->values();

        return response()->json([
            'status' => 'success',
            'data' => $products,
        ], 200);
    }

    public function chat(Request $request): \Illuminate\Http\JsonResponse
    {
        $question = trim((string) $request->input('question', ''));

        if ($question === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Pertanyaan tidak boleh kosong.',
            ], 422);
        }

        $allProducts = Product::with(['category', 'unit'])->get();
        $lowStockProducts = $allProducts->map(function (Product $product) {
            $minimumStock = max((int) ($product->minimum_stock ?? 5), 0);
            $stock = (int) $product->stock;

            if ($stock >= $minimumStock) {
                return null;
            }

            return [
                'id' => $product->id,
                'name' => $product->name,
                'stock' => $stock,
                'minimum_stock' => $minimumStock,
                'recommended_quantity' => max($minimumStock - $stock, 1),
            ];
        })->filter()->values();

        $normalizedQuestion = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $question));
        $normalizedQuestionWords = array_filter(preg_split('/\s+/', trim((string) $normalizedQuestion)) ?: [], fn ($word) => $word !== '');
        $matchingProduct = null;

        foreach ($allProducts as $product) {
            $normalizedProduct = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $product->name . ' ' . ($product->sku_code ?? '')));
            $productWords = array_filter(preg_split('/\s+/', trim((string) $normalizedProduct)) ?: [], fn ($word) => $word !== '');
            $sharedWords = array_values(array_intersect($normalizedQuestionWords, $productWords));

            if (
                str_contains($normalizedQuestion, $normalizedProduct)
                || str_contains($normalizedProduct, $normalizedQuestion)
                || (count($sharedWords) > 0 && count($sharedWords) >= min(2, count($productWords)))
            ) {
                $matchingProduct = $product;
                break;
            }
        }

        if ($matchingProduct) {
            $minimumStock = max((int) ($matchingProduct->minimum_stock ?? 5), 0);
            $stock = (int) $matchingProduct->stock;
            $difference = max($minimumStock - $stock, 0);
            $statusText = $difference > 0
                ? ' Saat ini masih di bawah batas minimum dengan kekurangan ' . $difference . ' pcs. Saya sarankan restok segera.'
                : ' Masih aman dan belum perlu restok.';

            $answer = 'Stok ' . $matchingProduct->name . ' saat ini ' . $stock . ' pcs. Batas minimum ' . $minimumStock . ' pcs.' . $statusText;
        } elseif (str_contains($normalizedQuestion, 'stok') || str_contains($normalizedQuestion, 'restok') || str_contains($normalizedQuestion, 'belanja')) {
            if ($lowStockProducts->isEmpty()) {
                $answer = 'Semua produk saat ini masih aman dari stok kritis. Tetap pantau pembelian agar ketersediaan tetap terjaga.';
            } else {
                $topItems = $lowStockProducts->take(3)->map(function ($product) {
                    return $product['name'] . ' (' . $product['stock'] . ' pcs, butuh ' . $product['recommended_quantity'] . ' pcs lagi)';
                })->implode('; ');

                $answer = 'Saya melihat stok kritis pada: ' . $topItems . '. Prioritaskan pembelian ulang agar penjualan tidak terganggu.';
            }
        } elseif (str_contains($normalizedQuestion, 'penjualan') || str_contains($normalizedQuestion, 'omzet') || str_contains($normalizedQuestion, 'jualan')) {
            $answer = 'Saat ini ada ' . count($lowStockProducts) . ' item yang membutuhkan perhatian stok. Saya sarankan untuk fokus pada pembelian ulang sebelum stok turun lebih jauh.';
        } elseif (str_contains($normalizedQuestion, 'halo') || str_contains($normalizedQuestion, 'hai') || str_contains($normalizedQuestion, 'hello')) {
            $answer = 'Halo! Saya bisa bantu cek stok, rekomendasi restok, atau ringkasan penjualan hari ini.';
        } else {
            $answer = 'Saya dapat membantu mengecek stok kritis, merekomendasikan pembelian ulang, dan memberi ringkasan kondisi penjualan hari ini. Coba tanya stok produk atau kebutuhan restok.';
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'answer' => $answer,
                'low_stock_products' => $lowStockProducts,
            ],
        ], 200);
    }

    /**
     * Simpan produk baru
     */
    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'nullable|exists:units,id',
            'sku_code' => 'required|string|unique:products,sku_code',
            'name' => 'required|string|max:255',
            'buy_price' => 'required|numeric|min:0',
            'sell_price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'minimum_stock' => 'nullable|integer|min:0',
        ]);

        $product = Product::create([
            'category_id' => $request->category_id,
            'unit_id' => $request->unit_id,
            'sku_code' => $request->sku_code,
            'name' => $request->name,
            'buy_price' => $request->buy_price,
            'sell_price' => $request->sell_price,
            'stock' => $request->stock,
            'minimum_stock' => $request->minimum_stock ?? 5,
        ]);

        // Load relasi agar response JSON langsung menampilkan detail kategori & unit
        $product->load(['category', 'unit']);

        return response()->json([
            'status' => 'success',
            'message' => 'Produk berhasil ditambahkan',
            'data' => $product,
        ], 201);
    }

    /**
     * Tampilkan satu data produk spesifik
     */
    public function show($id)
    {
        // Cari produk berdasarkan ID beserta relasinya
        $product = Product::with(['category', 'unit'])->find($id);

        if (! $product) {
            return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $product], 200);
    }

    /**
     * Update data produk
     */
    public function update(Request $request, $id)
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan'], 404);
        }

        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'nullable|exists:units,id',
            'sku_code' => 'required|string|unique:products,sku_code,' . $id,
            'name' => 'required|string|max:255',
            'buy_price' => 'required|numeric|min:0',
            'sell_price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'minimum_stock' => 'nullable|integer|min:0',
        ]);

        $product->update([
            'category_id' => $request->category_id,
            'unit_id' => $request->unit_id,
            'sku_code' => $request->sku_code,
            'name' => $request->name,
            'buy_price' => $request->buy_price,
            'sell_price' => $request->sell_price,
            'stock' => $request->stock,
            'minimum_stock' => $request->minimum_stock ?? $product->minimum_stock ?? 5,
        ]);

        $product->load(['category', 'unit']);

        return response()->json([
            'status' => 'success',
            'message' => 'Produk berhasil diupdate',
            'data' => $product,
        ], 200);
    }

    /**
     * Hapus produk
     */
    public function destroy($id)
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan'], 404);
        }

        $product->delete();

        return response()->json(['status' => 'success', 'message' => 'Produk berhasil dihapus'], 200);
    }
}
