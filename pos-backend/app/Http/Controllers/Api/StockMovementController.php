<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $movements = StockMovement::with(['product', 'user', 'supplier'])
            ->latest()
            ->paginate(50);

        if (!$request->user()->isOwner()) {
            $movements->getCollection()->each(function (StockMovement $movement): void {
                $movement->product?->makeHidden('buy_price');
            });
        }

        return response()->json(['status' => 'success', 'data' => $movements]);
    }

    /**
     * Penyesuaian manual hanya dapat dilakukan Owner melalui route role:admin.
     * User ID selalu diambil dari token, bukan dari payload browser.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'type' => ['required', 'in:in,out'],
            'quantity' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        $movement = DB::transaction(function () use ($validated, $request): StockMovement {
            $product = Product::query()
                ->whereKey($validated['product_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$product) {
                throw ValidationException::withMessages([
                    'product_id' => 'Produk tidak ditemukan atau sudah dinonaktifkan.',
                ]);
            }

            if ($validated['type'] === 'out' && $product->stock < $validated['quantity']) {
                throw ValidationException::withMessages([
                    'quantity' => "Stok {$product->name} tidak mencukupi. Stok tersedia: {$product->stock}.",
                ]);
            }

            $product->stock += $validated['type'] === 'in'
                ? $validated['quantity']
                : -$validated['quantity'];
            $product->save();

            return StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'type' => $validated['type'],
                'quantity' => $validated['quantity'],
                'description' => $validated['description'],
            ])->load(['product', 'user', 'supplier']);
        }, 3);

        return response()->json([
            'status' => 'success',
            'message' => 'Pergerakan stok berhasil dicatat.',
            'data' => $movement,
        ], 201);
    }
}
