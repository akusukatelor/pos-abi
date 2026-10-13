<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index()
    {
        $units = Unit::all();
        return response()->json(['status' => 'success', 'data' => $units], 200);
    }

    public function store(Request $request)
    {
        // Validasi: name wajib diisi, short_name opsional
        $request->validate([
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:50'
        ]);

        $unit = Unit::create([
            'name' => $request->name,
            'short_name' => $request->short_name
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Satuan barang berhasil ditambahkan',
            'data' => $unit
        ], 201);
    }

    public function show($id)
    {
        $unit = Unit::find($id);

        if (!$unit) {
            return response()->json(['status' => 'error', 'message' => 'Data satuan tidak ditemukan'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $unit], 200);
    }

    public function update(Request $request, $id)
    {
        $unit = Unit::find($id);

        if (!$unit) {
            return response()->json(['status' => 'error', 'message' => 'Data satuan tidak ditemukan'], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:50'
        ]);

        $unit->update([
            'name' => $request->name,
            'short_name' => $request->short_name
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Satuan barang berhasil diupdate',
            'data' => $unit
        ], 200);
    }

    public function destroy($id)
    {
        $unit = Unit::find($id);

        if (!$unit) {
            return response()->json(['status' => 'error', 'message' => 'Data satuan tidak ditemukan'], 404);
        }

        $unit->delete();

        return response()->json(['status' => 'success', 'message' => 'Satuan barang berhasil dihapus'], 200);
    }
}