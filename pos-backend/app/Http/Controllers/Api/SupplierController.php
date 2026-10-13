<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::all();
        return response()->json(['status' => 'success', 'data' => $suppliers], 200);
    }

    public function store(Request $request)
    {
        // Validasi: hanya nama yang wajib, sisanya opsional
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255'
        ]);

        $supplier = Supplier::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
            'contact_person' => $request->contact_person
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier berhasil ditambahkan',
            'data' => $supplier
        ], 201);
    }

    public function show($id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json(['status' => 'error', 'message' => 'Data supplier tidak ditemukan'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $supplier], 200);
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json(['status' => 'error', 'message' => 'Data supplier tidak ditemukan'], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255'
        ]);

        $supplier->update([
            'name' => $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
            'contact_person' => $request->contact_person
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier berhasil diupdate',
            'data' => $supplier
        ], 200);
    }

    public function destroy($id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json(['status' => 'error', 'message' => 'Data supplier tidak ditemukan'], 404);
        }

        $supplier->delete();

        return response()->json(['status' => 'success', 'message' => 'Supplier berhasil dihapus'], 200);
    }
}