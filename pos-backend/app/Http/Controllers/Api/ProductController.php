<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['category', 'unit']);

        // Produk nonaktif hanya dapat ditampilkan secara eksplisit oleh Owner.
        if (!$request->user()->isOwner() || !$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('sku_code', 'LIKE', "%{$search}%");
            });
        }

        $products = $query->latest()->paginate(50);

        // Kasir only receives the master purchase price when loading the Inventory
        // receiving form. The normal POS catalog continues to hide this field.
        $canViewPurchasePrice = $request->user()->isOwner()
            || ($request->user()->role === 'kasir' && $request->boolean('for_receiving'));

        if (!$canViewPurchasePrice) {
            $products->getCollection()->each(fn (Product $product) => $product->makeHidden('buy_price'));
        }

        return response()->json([
            'status' => 'success',
            'data' => $products,
        ]);
    }

    public function restock(): \Illuminate\Http\JsonResponse
    {
        $products = Product::with(['category', 'unit'])
            ->where('is_active', true)
            ->get()
            ->map(function (Product $product) {
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

        $isOwner = $request->user()?->isOwner() ?? false;

        $defaultSuggestions = $isOwner
            ? ['Produk stok habis?', 'Rekomendasi restok', 'Tugas menunggu verifikasi', 'Ringkasan operasional']
            : ['Produk stok habis?', 'Rekomendasi restok', 'Cek draft penerimaan'];

        $allProducts = Product::with(['category', 'unit'])
            ->where('is_active', true)
            ->get();

        $outOfStockProducts = $allProducts->filter(fn (Product $p) => (int) $p->stock <= 0)->values();

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
        $rawWords = array_filter(preg_split('/\s+/', trim((string) $normalizedQuestion)) ?: [], fn ($w) => $w !== '');
        $stopWords = [
            'cek', 'stok', 'harga', 'jual', 'beli', 'modal', 'berapa', 'produk', 'barang',
            'ada', 'sisa', 'yang', 'ini', 'itu', 'dong', 'ya', 'tolong', 'lihat', 'info',
            'apakah', 'masih', 'tersedia', 'habis', 'kosong', 'restok', 'kritis', 'menipis',
        ];
        $queryWords = array_values(array_diff($rawWords, $stopWords));

        // 1. Cek pencarian produk spesifik berdasarkan nama atau SKU
        $matchingProduct = null;
        if (count($queryWords) > 0) {
            $cleanQuery = implode(' ', $queryWords);
            foreach ($allProducts as $product) {
                $normalizedProduct = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $product->name . ' ' . ($product->sku_code ?? '')));
                $productWords = array_filter(preg_split('/\s+/', trim((string) $normalizedProduct)) ?: [], fn ($w) => $w !== '');
                $sharedWords = array_values(array_intersect($queryWords, $productWords));

                if (
                    (strlen($cleanQuery) >= 3 && str_contains($normalizedProduct, $cleanQuery))
                    || (count($sharedWords) > 0 && count($sharedWords) >= min(2, count($productWords)))
                ) {
                    $matchingProduct = $product;
                    break;
                }
            }
        }

        // Batasi informasi harga beli/modal hanya untuk Owner
        $asksCost = preg_match('/\b(harga beli|harga modal|modal|hpp|margin)\b/', $normalizedQuestion) === 1;
        if ($asksCost && !$isOwner && !str_contains($normalizedQuestion, 'verifikasi')) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'answer' => 'Informasi harga modal dan margin hanya dapat diakses oleh akun Owner.',
                    'low_stock_products' => $lowStockProducts,
                    'suggestions' => $defaultSuggestions,
                ],
            ], 200);
        }

        $suggestions = $defaultSuggestions;

        if ($matchingProduct) {
            $minimumStock = max((int) ($matchingProduct->minimum_stock ?? 5), 0);
            $stock = (int) $matchingProduct->stock;
            $unitName = $matchingProduct->unit?->name ?? 'pcs';
            $sellPrice = 'Rp ' . number_format((float) $matchingProduct->sell_price, 0, ',', '.');
            $difference = max($minimumStock - $stock, 0);

            $statusText = $stock <= 0
                ? 'Status: STOK HABIS. Segera lakukan pembelian ke supplier.'
                : ($difference > 0
                    ? "Status: Stok menipis (kurang {$difference} {$unitName} dari batas minimum {$minimumStock} {$unitName})."
                    : "Status: Aman (batas minimum {$minimumStock} {$unitName}).");

            $priceLine = "Harga jual: {$sellPrice}";
            if ($isOwner) {
                $buyPrice = 'Rp ' . number_format((float) $matchingProduct->buy_price, 0, ',', '.');
                $priceLine .= " | Harga beli: {$buyPrice}";
            }

            $answer = "{$matchingProduct->name} ({$matchingProduct->sku_code})\n"
                . "• Sisa stok: {$stock} {$unitName}\n"
                . "• {$priceLine}\n"
                . "• {$statusText}";

            $suggestions = ['Rekomendasi restok', 'Produk stok habis?', 'Ringkasan operasional'];
        } elseif (str_contains($normalizedQuestion, 'habis') || str_contains($normalizedQuestion, 'kosong')) {
            if ($outOfStockProducts->isEmpty()) {
                $answer = 'Tidak ada produk dengan stok 0 saat ini. Semua katalog aktif masih memiliki stok.';
            } else {
                $list = $outOfStockProducts->take(6)->map(fn (Product $p) => "• {$p->name} ({$p->sku_code})")->implode("\n");
                $extra = $outOfStockProducts->count() > 6 ? "\n...dan " . ($outOfStockProducts->count() - 6) . ' produk lainnya.' : '';
                $answer = "Ditemukan {$outOfStockProducts->count()} produk dengan stok habis (0):\n{$list}{$extra}";
            }
        } elseif (str_contains($normalizedQuestion, 'verifikasi') || str_contains($normalizedQuestion, 'tugas') || str_contains($normalizedQuestion, 'opname') || str_contains($normalizedQuestion, 'draft')) {
            $draftReceipts = \App\Models\PurchaseReceipt::query()->where('status', 'draft')->count();
            $pendingOpnames = \App\Models\StockOpname::query()->where('status', 'pending_approval')->count();
            $unverifiedServices = \App\Models\ServiceTransaction::query()
                ->where('status', 'completed')
                ->whereNull('margin_verified_by')
                ->count();

            $answer = "Status dokumen yang perlu tindak lanjut:\n"
                . "• Penerimaan supplier (Draft): {$draftReceipts} dokumen\n"
                . "• Stock opname (Menunggu Owner): {$pendingOpnames} dokumen\n"
                . "• Layanan PPOB/BRILink (Belum verifikasi margin): {$unverifiedServices} transaksi";
        } elseif (str_contains($normalizedQuestion, 'ringkasan') || str_contains($normalizedQuestion, 'operasional') || str_contains($normalizedQuestion, 'penjualan') || str_contains($normalizedQuestion, 'omzet')) {
            $todaySalesCount = \App\Models\Transaction::query()->whereDate('created_at', today())->count();
            $unverifiedServices = \App\Models\ServiceTransaction::query()
                ->where('status', 'completed')
                ->whereNull('margin_verified_by')
                ->count();

            $answer = "Ringkasan kondisi toko hari ini:\n"
                . "• Transaksi kasir hari ini: {$todaySalesCount} transaksi\n"
                . "• Produk stok habis: {$outOfStockProducts->count()} item\n"
                . "• Produk di bawah minimum stok: {$lowStockProducts->count()} item\n"
                . "• Layanan digital belum verifikasi margin: {$unverifiedServices} transaksi";
        } elseif (str_contains($normalizedQuestion, 'stok') || str_contains($normalizedQuestion, 'restok') || str_contains($normalizedQuestion, 'belanja') || str_contains($normalizedQuestion, 'kritis')) {
            if ($lowStockProducts->isEmpty()) {
                $answer = 'Semua produk saat ini masih aman dari batas minimum stok.';
            } else {
                $topItems = $lowStockProducts->take(5)->map(function ($product) {
                    return "• {$product['name']}: sisa {$product['stock']} (saran beli +{$product['recommended_quantity']})";
                })->implode("\n");
                $extra = $lowStockProducts->count() > 5 ? "\n...total {$lowStockProducts->count()} produk perlu restok." : '';

                $answer = "Rekomendasi restok prioritas:\n{$topItems}{$extra}";
            }
        } elseif (str_contains($normalizedQuestion, 'halo') || str_contains($normalizedQuestion, 'hai') || str_contains($normalizedQuestion, 'hello')) {
            $answer = 'Halo! Saya siap membantu mengecek sisa stok produk, rekomendasi restok, maupun dokumen yang menunggu verifikasi.';
        } else {
            $answer = "Saya bisa membantu mengecek:\n"
                . "• Stok & harga produk (ketik nama produk, misal: \"cek stok indomie\")\n"
                . "• Daftar produk habis atau perlu restok\n"
                . "• Dokumen penerimaan draft, stock opname, dan verifikasi margin layanan";
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'answer' => $answer,
                'low_stock_products' => $lowStockProducts,
                'suggestions' => $suggestions,
            ],
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'sku_code' => ['required', 'string', 'max:100', 'unique:products,sku_code'],
            'name' => ['required', 'string', 'max:255'],
            'buy_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'minimum_stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $initialStock = (int) ($validated['stock'] ?? 0);
        $minimumStock = (int) ($validated['minimum_stock'] ?? 5);
        unset($validated['stock'], $validated['minimum_stock']);

        $product = DB::transaction(function () use ($validated, $initialStock, $minimumStock, $request): Product {
            $product = Product::create([
                ...$validated,
                'stock' => 0,
                'minimum_stock' => $minimumStock,
                'is_active' => true,
            ]);

            if ($initialStock > 0) {
                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => 'in',
                    'quantity' => $initialStock,
                    'description' => 'Stok awal saat produk dibuat.',
                ]);

                $product->stock = $initialStock;
                $product->save();
            }

            return $product->load(['category', 'unit']);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Produk berhasil ditambahkan.',
            'data' => $product,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $product = Product::with(['category', 'unit'])
            ->where('is_active', true)
            ->find($id);

        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan.'], 404);
        }

        if (!$request->user()->isOwner()) {
            $product->makeHidden('buy_price');
        }

        return response()->json(['status' => 'success', 'data' => $product]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'unit_id' => ['sometimes', 'nullable', 'integer', 'exists:units,id'],
            'sku_code' => ['sometimes', 'required', 'string', 'max:100', 'unique:products,sku_code,'.$id],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'buy_price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'buy_price_change_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sell_price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0'],
            'stock' => ['prohibited'],
            'minimum_stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        // Keep compatibility with the Laravel version used by this project:
        // validate the dependency explicitly instead of using prohibited_without.
        if (
            array_key_exists('buy_price_change_reason', $validated)
            && !array_key_exists('buy_price', $validated)
        ) {
            throw ValidationException::withMessages([
                'buy_price_change_reason' => 'Alasan perubahan harga beli hanya boleh dikirim bersama harga beli.',
            ]);
        }

        $changeReason = trim((string) ($validated['buy_price_change_reason'] ?? ''));
        unset($validated['buy_price_change_reason']);

        if (empty($validated)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tidak ada perubahan data produk yang dikirim.',
            ], 422);
        }

        $product = DB::transaction(function () use ($validated, $changeReason, $id, $request): ?Product {
            $product = Product::query()
                ->where('is_active', true)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (!$product) {
                return null;
            }

            if (array_key_exists('buy_price', $validated)) {
                $oldPriceCents = (int) round(((float) $product->buy_price) * 100);
                $newPriceCents = (int) round(((float) $validated['buy_price']) * 100);

                if ($oldPriceCents !== $newPriceCents) {
                    if ($changeReason === '') {
                        throw ValidationException::withMessages([
                            'buy_price_change_reason' => 'Alasan perubahan harga beli wajib diisi.',
                        ]);
                    }

                    ProductPriceHistory::query()->create([
                        'product_id' => $product->id,
                        'changed_by' => $request->user()->id,
                        'old_price' => number_format($oldPriceCents / 100, 2, '.', ''),
                        'new_price' => number_format($newPriceCents / 100, 2, '.', ''),
                        'reason' => $changeReason,
                    ]);
                }
            }

            $product->update($validated);

            return $product->load(['category', 'unit']);
        }, 3);

        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Data produk berhasil diperbarui. Perubahan harga beli master dicatat dalam riwayat audit.',
            'data' => $product,
        ]);
    }

    public function priceHistory(int $id)
    {
        $product = Product::query()->find($id);

        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan.'], 404);
        }

        $histories = ProductPriceHistory::query()
            ->with('changer:id,name')
            ->where('product_id', $product->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $histories,
        ]);
    }

    public function destroy($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan.'], 404);
        }

        $product->update(['is_active' => false]);

        return response()->json([
            'status' => 'success',
            'message' => 'Produk dinonaktifkan. Riwayat transaksi dan pergerakan stok tetap dipertahankan.',
        ]);
    }
}
