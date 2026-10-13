<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Transaction;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    /**
     * Tampilkan riwayat semua shift kasir
     */
    public function index()
    {
        $shifts = Shift::with('user')->orderBy('opened_at', 'desc')->get();
        return response()->json(['status' => 'success', 'data' => $shifts]);
    }

    /**
     * BUKA SHIFT (Kasir baru masuk kerja)
     */
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'opening_balance' => 'required|numeric|min:0', // Uang modal/kembalian awal di laci
        ]);

        // Cek apakah kasir ini masih punya shift yang berstatus 'open' (belum ditutup)
        $activeShift = Shift::where('user_id', $request->user_id)->where('status', 'open')->first();
        if ($activeShift) {
            return response()->json([
                'status' => 'error', 
                'message' => 'Anda masih memiliki shift yang belum ditutup. Silakan tutup shift sebelumnya terlebih dahulu.'
            ], 400);
        }

        $shift = Shift::create([
            'user_id' => $request->user_id,
            'opening_balance' => $request->opening_balance,
            'opened_at' => now(), // Catat waktu saat ini
            'status' => 'open'
        ]);

        return response()->json(['status' => 'success', 'message' => 'Shift berhasil dibuka. Selamat bekerja!', 'data' => $shift], 201);
    }

    /**
     * TAMPILKAN DETAIL SHIFT (Beserta total pendapatan sementara)
     */
    public function show($id)
    {
        $shift = Shift::with('user')->find($id);
        
        if (!$shift) {
            return response()->json(['status' => 'error', 'message' => 'Data shift tidak ditemukan'], 404);
        }
        
        // Fitur Tambahan: Jika shift masih open, kita hitung pendapatannya SECARA LIVE
        if ($shift->status === 'open') {
            $liveSales = Transaction::where('user_id', $shift->user_id)
                        ->whereBetween('created_at', [$shift->opened_at, now()])
                        ->sum('pay_amount');
                        
            $shift->live_expected_balance = $shift->opening_balance + $liveSales;
        }

        return response()->json(['status' => 'success', 'data' => $shift]);
    }

    /**
     * TUTUP SHIFT (Kasir pulang kerja)
     */
    public function update(Request $request, $id)
    {
        $shift = Shift::find($id);
        
        if (!$shift) {
            return response()->json(['status' => 'error', 'message' => 'Data shift tidak ditemukan'], 404);
        }

        if ($shift->status === 'closed') {
            return response()->json(['status' => 'error', 'message' => 'Shift ini sudah ditutup sebelumnya.'], 400);
        }

        $request->validate([
            'closing_balance_actual' => 'required|numeric|min:0' // Uang fisik yang benar-benar ada di laci saat dihitung kasir
        ]);

        // 1. Hitung total uang yang masuk (penjualan) selama shift ini berjalan
        $totalSales = Transaction::where('user_id', $shift->user_id)
                        ->whereBetween('created_at', [$shift->opened_at, now()])
                        ->sum('pay_amount'); 

        // 2. Uang yang seharusnya ada di sistem (Modal Awal + Hasil Penjualan)
        $systemBalance = $shift->opening_balance + $totalSales;

        // 3. Update status shift menjadi 'closed' dan catat hasilnya
        $shift->update([
            'closed_at' => now(),
            'closing_balance_system' => $systemBalance,
            'closing_balance_actual' => $request->closing_balance_actual,
            'status' => 'closed'
        ]);

        // 4. Analisa apakah uang laci selisih (kurang/lebih)
        $selisih = $request->closing_balance_actual - $systemBalance;
        $pesan_selisih = "";
        
        if ($selisih < 0) {
            $pesan_selisih = "Peringatan: Uang fisik kurang Rp " . abs($selisih);
        } elseif ($selisih > 0) {
            $pesan_selisih = "Catatan: Uang fisik lebih Rp " . abs($selisih);
        } else {
            $pesan_selisih = "Laporan valid. Uang fisik sesuai dengan sistem.";
        }

        return response()->json([
            'status' => 'success', 
            'message' => 'Shift berhasil ditutup. ' . $pesan_selisih, 
            'data' => $shift
        ]);
    }

    /**
     * HAPUS SHIFT (Hati-hati menggunakannya)
     */
    public function destroy($id)
    {
        $shift = Shift::find($id);
        
        if (!$shift) {
            return response()->json(['status' => 'error', 'message' => 'Data shift tidak ditemukan'], 404);
        }
        
        // Opsional: Anda bisa tambahkan validasi agar hanya Admin yang bisa menghapus ini
        $shift->delete();
        
        return response()->json(['status' => 'success', 'message' => 'Data riwayat shift berhasil dihapus']);
    }
}