<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\Product;

class TransactionController extends Controller
{
    /**
     * Tampilkan riwayat transaksi (Untuk halaman riwayat kasir/admin)
     */
    public function index()
    {
        // Ambil data transaksi beserta nama kasir, pelanggan (jika ada), dan detail struknya
        $transactions = Transaction::with(['user', 'customer', 'details.product'])->latest()->get();
        
        return response()->json([
            'status' => 'success',
            'data' => $transactions
        ]);
    }

    /**
     * PROSES CHECKOUT (Sangat Penting)
     */
    public function store(Request $request)
    {
        // 1. Validasi Input dari Frontend (React)
        $request->validate([
            'user_id' => 'required|exists:users,id', // Siapa kasirnya (biasanya dari token auth)
            'customer_id' => 'nullable|exists:customers,id',
            'pay_amount' => 'required|numeric|min:0',
            
            // Memastikan frontend mengirim array 'items' berisi barang yang dibeli
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        // Mulai Database Transaction (Keamanan Data)
        DB::beginTransaction();

        try {
            // 2. Hitung Total Belanja Ulang di Backend (Jangan percaya total dari Frontend)
            $total_price = 0;
            $items_to_save = [];

            foreach ($request->items as $item) {
                // Ambil data produk terbaru dari database (terutama harganya saat ini)
                $product = Product::lockForUpdate()->find($item['product_id']); // lockForUpdate mencegah bentrok jika kasir lain beli barang sama
                
                // Cek stok apakah cukup
                if ($product->stock < $item['quantity']) {
                    throw new \Exception("Stok tidak cukup untuk produk: " . $product->name);
                }

                $subtotal = $product->sell_price * $item['quantity'];
                $total_price += $subtotal;

                // Kurangi stok produk
                $product->stock -= $item['quantity'];
                $product->save();

                // Siapkan data detail untuk disimpan nanti
                $items_to_save[] = [
                    'product_id' => $product->id,
                    'quantity'   => $item['quantity'],
                    'price'      => $product->sell_price,
                    'subtotal'   => $subtotal
                ];
            }

            // 3. Buat Header Transaksi (Struk)
            $invoice_number = 'INV-' . date('YmdHis') . '-' . rand(100, 999);
            
            // Cek apakah bayarnya kurang (jika iya, berarti hutang/unpaid)
            $payment_status = ($request->pay_amount >= $total_price) ? 'paid' : 'unpaid';

            $transaction = Transaction::create([
                'user_id' => $request->user_id,
                'customer_id' => $request->customer_id,
                'invoice_number' => $invoice_number,
                'total_price' => $total_price,
                'pay_amount' => $request->pay_amount,
                'payment_status' => $payment_status,
                'discount_amount' => 0, // Misal belum pakai diskon
                'due_date' => ($payment_status == 'unpaid') ? now()->addDays(7) : null // Jatuh tempo 7 hari
            ]);

            // 4. Simpan Detail Transaksi
            foreach ($items_to_save as $detail) {
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'product_id'     => $detail['product_id'],
                    'quantity'       => $detail['quantity'],
                    'price'          => $detail['price'],
                    'subtotal'       => $detail['subtotal']
                ]);
            }

            // Jika semua langkah di atas sukses, SIMPAN PERMANEN
            DB::commit();

            // Load data relasi untuk response JSON ke kasir (untuk cetak struk)
            $transaction->load(['details.product', 'customer', 'user']);

            return response()->json([
                'status' => 'success',
                'message' => 'Transaksi berhasil',
                'data' => $transaction
            ], 201);

        } catch (\Exception $e) {
            // Jika ada ERROR di baris manapun, BATALKAN SEMUA PERUBAHAN
            DB::rollback();

            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi gagal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tampilkan detail 1 transaksi spesifik (Untuk lihat struk ulang)
     */
    public function show($id)
    {
        $transaction = Transaction::with(['user', 'customer', 'details.product'])->find($id);

        if (!$transaction) {
            return response()->json(['status' => 'error', 'message' => 'Transaksi tidak ditemukan'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $transaction]);
    }
}