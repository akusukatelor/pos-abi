<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnlineOrderManagementController extends Controller
{
    public function index()
    {
        $orders = OnlineOrder::with(['customer:id,name,phone', 'customerAccount:id,email', 'items'])
            ->latest()->paginate(20);
        return response()->json(['status' => 'success', 'data' => $orders]);
    }

    /**
     * Hanya dipanggil Owner SETELAH menerima uang tunai dan menyerahkan barang.
     * Pesanan pending tidak mengurangi stok dan tidak dianggap pembayaran berhasil.
     */
    public function complete(Request $request, int $id)
    {
        $request->validate([
            'cash_received_confirmed' => ['required', 'accepted'],
            'goods_handed_over_confirmed' => ['required', 'accepted'],
        ]);

        $order = DB::transaction(function () use ($request, $id): OnlineOrder {
            $order = OnlineOrder::with('items')->lockForUpdate()->findOrFail($id);
            if ($order->status === 'completed') {
                return $order; // retry tidak memotong stok atau membuat transaksi baru
            }
            if ($order->status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'Pesanan ini sudah dibatalkan.']);
            }

            $lines = $order->items->sortBy('product_id');
            $products = [];
            foreach ($lines as $line) {
                $product = Product::whereKey($line->product_id)->where('is_active', true)
                    ->lockForUpdate()->first();
                if (!$product || $product->stock < $line->quantity) {
                    throw ValidationException::withMessages([
                        'order' => 'Stok tidak mencukupi untuk menyelesaikan pesanan. Jangan tandai pembayaran lunas sebelum diselesaikan.',
                    ]);
                }
                $products[$product->id] = $product;
            }

            $invoice = 'INV-'.$order->order_number;
            $transaction = Transaction::create([
                'user_id' => $request->user()->id,
                'customer_id' => $order->customer_id,
                'invoice_number' => $invoice,
                'total_price' => $order->total_amount,
                'pay_amount' => $order->total_amount,
                'payment_method' => 'cash',
                'change_amount' => '0.00',
                'discount_amount' => '0.00',
                'payment_status' => 'paid',
                'due_date' => null,
                'idempotency_key' => (string) Str::uuid(),
                'idempotency_hash' => hash('sha256', 'online-order:'.$order->id),
            ]);

            foreach ($lines as $line) {
                $product = $products[$line->product_id];
                $product->stock -= $line->quantity;
                $product->save();

                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $product->id,
                    'quantity' => $line->quantity,
                    'price' => $line->unit_price,
                    'subtotal' => $line->subtotal,
                ]);

                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => 'out',
                    'quantity' => $line->quantity,
                    'description' => 'Penjualan online '.$order->order_number,
                ]);
            }

            $order->update([
                'status' => 'completed',
                'payment_status' => 'paid',
                'transaction_id' => $transaction->id,
                'processed_by' => $request->user()->id,
                'completed_at' => now(),
            ]);

            // Poin loyalty BELUM diberikan: aturan earn/redeem menunggu Owner.
            return $order;
        }, 3);

        return response()->json(['status' => 'success', 'data' => $order->load('items')]);
    }

    public function cancel(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $order = DB::transaction(function () use ($request, $data, $id): OnlineOrder {
            $order = OnlineOrder::lockForUpdate()->findOrFail($id);
            if ($order->status !== 'pending') {
                throw ValidationException::withMessages([
                    'order' => 'Hanya pesanan yang masih pending dapat dibatalkan.',
                ]);
            }
            $order->update([
                'status' => 'cancelled',
                'processed_by' => $request->user()->id,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($data['reason']),
            ]);
            return $order;
        }, 3);

        return response()->json(['status' => 'success', 'data' => $order]);
    }
}
