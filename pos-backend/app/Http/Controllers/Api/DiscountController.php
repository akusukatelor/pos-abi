<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Discount;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function index()
    {
        $discounts = Discount::all();
        return response()->json(['status' => 'success', 'data' => $discounts]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:percentage,fixed_amount',
            'value' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'boolean'
        ]);

        $discount = Discount::create($request->all());
        return response()->json(['status' => 'success', 'message' => 'Promo/Diskon berhasil dibuat', 'data' => $discount], 201);
    }

    public function show($id)
    {
        $discount = Discount::find($id);
        
        if (!$discount) {
            return response()->json(['status' => 'error', 'message' => 'Data diskon tidak ditemukan'], 404);
        }
        
        return response()->json(['status' => 'success', 'data' => $discount]);
    }

    public function update(Request $request, $id)
    {
        $discount = Discount::find($id);
        
        if (!$discount) {
            return response()->json(['status' => 'error', 'message' => 'Data diskon tidak ditemukan'], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:percentage,fixed_amount',
            'value' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'boolean'
        ]);

        $discount->update($request->all());

        return response()->json(['status' => 'success', 'message' => 'Promo/Diskon berhasil diupdate', 'data' => $discount]);
    }

    public function destroy($id)
    {
        $discount = Discount::find($id);
        
        if (!$discount) {
            return response()->json(['status' => 'error', 'message' => 'Data diskon tidak ditemukan'], 404);
        }
        
        $discount->delete();
        
        return response()->json(['status' => 'success', 'message' => 'Promo/Diskon berhasil dihapus']);
    }
}