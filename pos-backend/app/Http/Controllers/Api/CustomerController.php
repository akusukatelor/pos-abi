<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index()
    {
        // Menampilkan pelanggan, mungkin diurutkan dari yang utangnya paling banyak
        $customers = Customer::orderBy('total_debt', 'desc')->get();
        return response()->json(['status' => 'success', 'data' => $customers]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
        ]);

        $customer = Customer::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
            'total_debt' => 0 // Awal mula tidak punya utang
        ]);

        return response()->json(['status' => 'success', 'message' => 'Pelanggan berhasil ditambah', 'data' => $customer], 201);
    }

    public function show($id)
    {
        // Ambil data pelanggan beserta riwayat transaksinya
        $customer = Customer::with('transactions')->find($id);
        
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Tidak ditemukan'], 404);
        
        return response()->json(['status' => 'success', 'data' => $customer]);
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::find($id);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Tidak ditemukan'], 404);

        $request->validate(['name' => 'required|string|max:255']);
        $customer->update($request->only(['name', 'phone', 'address']));

        return response()->json(['status' => 'success', 'message' => 'Data diupdate', 'data' => $customer]);
    }

    public function destroy($id)
    {
        $customer = Customer::find($id);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Tidak ditemukan'], 404);
        
        $customer->delete();
        return response()->json(['status' => 'success', 'message' => 'Dihapus']);
    }
}