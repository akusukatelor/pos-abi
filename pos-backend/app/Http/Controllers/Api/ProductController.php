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

        $allProducts = Product::with(['category', 'unit'])
            ->where('is_active', true)
            ->get();
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
