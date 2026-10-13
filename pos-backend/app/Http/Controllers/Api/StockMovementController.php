<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function index()
    {
        // Tampilkan riwayat stok masuk/keluar beserta nama produk, user, dan supplier
        $movements = StockMovement::with(['product', 'user', 'supplier'])->latest()->get();
        return response()->json(['status' => 'success', 'data' => $movements]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'user_id' => 'required|exists:users,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'type' => 'required|in:in,out',
            'quantity' => 'required|integer|min:1',
            'description' => 'nullable|string'
        ]);

        DB::beginTransaction();
        try {
            // 1. Simpan log riwayat pergerakan stok
            $movement = StockMovement::create($request->all());

            // 2. Update (Tambah/Kurang) stok di tabel Master Produk
            $product = Product::lockForUpdate()->find($request->product_id);
            
            if ($request->type === 'in') {
                $product->stock += $request->quantity; // Barang masuk (kulakan)
            } else {
                // Barang keluar (rusak/retur)
                if ($product->stock < $request->quantity) {
                    throw new \Exception("Stok tidak cukup untuk dikeluarkan.");
                }
                $product->stock -= $request->quantity; 
            }
            $product->save();

            DB::commit();

            return response()->json([
                'status' => 'success', 
                'message' => 'Stok berhasil diperbarui', 
                'data' => $movement->load('product')
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // Untuk fungsi Update dan Destroy pada riwayat stok, biasanya DINONAKTIFKAN (tidak dibuat)
    // demi keamanan audit. Riwayat stok tidak boleh dihapus atau diedit agar datanya tidak dimanipulasi kasir nakal.
}