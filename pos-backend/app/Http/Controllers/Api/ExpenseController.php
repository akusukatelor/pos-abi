<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index()
    {
        $expenses = Expense::with('user')->orderBy('expense_date', 'desc')->get();
        return response()->json(['status' => 'success', 'data' => $expenses]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0',
            'category' => 'required|string|max:255',
            'description' => 'nullable|string',
            'expense_date' => 'required|date'
        ]);

        $expense = Expense::create($request->all());
        return response()->json(['status' => 'success', 'message' => 'Pengeluaran berhasil dicatat', 'data' => $expense], 201);
    }

    public function show($id)
    {
        $expense = Expense::with('user')->find($id);
        
        if (!$expense) {
            return response()->json(['status' => 'error', 'message' => 'Data pengeluaran tidak ditemukan'], 404);
        }
        
        return response()->json(['status' => 'success', 'data' => $expense]);
    }

    public function update(Request $request, $id)
    {
        $expense = Expense::find($id);
        
        if (!$expense) {
            return response()->json(['status' => 'error', 'message' => 'Data pengeluaran tidak ditemukan'], 404);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0',
            'category' => 'required|string|max:255',
            'description' => 'nullable|string',
            'expense_date' => 'required|date'
        ]);

        // Catatan: Biasanya user_id tidak diubah saat update
        $expense->update($request->only(['amount', 'category', 'description', 'expense_date']));

        return response()->json(['status' => 'success', 'message' => 'Data pengeluaran berhasil diupdate', 'data' => $expense]);
    }

    public function destroy($id)
    {
        $expense = Expense::find($id);
        
        if (!$expense) {
            return response()->json(['status' => 'error', 'message' => 'Data pengeluaran tidak ditemukan'], 404);
        }
        
        $expense->delete();
        
        return response()->json(['status' => 'success', 'message' => 'Data pengeluaran berhasil dihapus']);
    }
}