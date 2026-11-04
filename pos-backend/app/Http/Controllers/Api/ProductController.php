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
        ]);

        $initialStock = (int) ($validated['stock'] ?? 0);
        unset($validated['stock']);

        $product = DB::transaction(function () use ($validated, $initialStock, $request): Product {
            $product = Product::create([
                ...$validated,
                'stock' => 0,
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
