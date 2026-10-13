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
            'data' => $products
        ], 200);
    }

    /**
     * Simpan produk baru
     */
    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'unit_id'     => 'nullable|exists:units,id', // Opsional, tapi jika diisi harus valid
            'sku_code'    => 'required|string|unique:products,sku_code',
            'name'        => 'required|string|max:255',
            'buy_price'   => 'required|numeric|min:0',
            'sell_price'  => 'required|numeric|min:0',
            'stock'       => 'required|integer|min:0'
        ]);

        $product = Product::create([
            'category_id' => $request->category_id,
            'unit_id'     => $request->unit_id,
            'sku_code'    => $request->sku_code,
            'name'        => $request->name,
            'buy_price'   => $request->buy_price,
            'sell_price'  => $request->sell_price,
            'stock'       => $request->stock
        ]);

        // Load relasi agar response JSON langsung menampilkan detail kategori & unit
        $product->load(['category', 'unit']);

        return response()->json([
            'status' => 'success',
            'message' => 'Produk berhasil ditambahkan',
            'data' => $product
        ], 201);
    }

    /**
     * Tampilkan satu data produk spesifik
     */
    public function show($id)
    {
        // Cari produk berdasarkan ID beserta relasinya
        $product = Product::with(['category', 'unit'])->find($id);

        if (!$product) {
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

        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan'], 404);
        }

        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'unit_id'     => 'nullable|exists:units,id',
            // Rule unique: abaikan pengecekan unique untuk ID produk ini sendiri
            'sku_code'    => 'required|string|unique:products,sku_code,' . $id,
            'name'        => 'required|string|max:255',
            'buy_price'   => 'required|numeric|min:0',
            'sell_price'  => 'required|numeric|min:0',
            'stock'       => 'required|integer|min:0'
        ]);

        $product->update([
            'category_id' => $request->category_id,
            'unit_id'     => $request->unit_id,
            'sku_code'    => $request->sku_code,
            'name'        => $request->name,
            'buy_price'   => $request->buy_price,
            'sell_price'  => $request->sell_price,
            'stock'       => $request->stock
        ]);

        $product->load(['category', 'unit']);

        return response()->json([
            'status' => 'success',
            'message' => 'Produk berhasil diupdate',
            'data' => $product
        ], 200);
    }

    /**
     * Hapus produk
     */
    public function destroy($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Produk tidak ditemukan'], 404);
        }

        $product->delete();

        return response()->json(['status' => 'success', 'message' => 'Produk berhasil dihapus'], 200);
    }
}