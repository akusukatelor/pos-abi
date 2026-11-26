<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Services\LoyaltyPointService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnlineOrderManagementController extends Controller
{
    private const ACTIVE_STATUSES = ['pending', 'processing', 'ready'];

    public function index()
    {
        $orders = OnlineOrder::with(['customer:id,name,phone', 'customerAccount:id,email', 'items'])
            ->latest()->paginate(20);
        return response()->json(['status' => 'success', 'data' => $orders]);
    }

    /**
     * Mengubah progres persiapan pesanan: pending -> processing -> ready.
     * Belum memotong stok dan belum mencatat penerimaan kas.
     */
    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', 'in:processing,ready'],
        ]);

        $allowedNext = [
            'pending' => ['processing', 'ready'],
            'processing' => ['ready'],
        ];

        $order = DB::transaction(function () use ($request, $data, $id, $allowedNext): OnlineOrder {
            $order = OnlineOrder::lockForUpdate()->findOrFail($id);
            $nextStatus = $data['status'];

            if ($order->status === $nextStatus) {
                return $order;
            }

            if (!in_array($nextStatus, $allowedNext[$order->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "Status pesanan tidak dapat diubah dari {$order->status} ke {$nextStatus}.",
                ]);
            }

            $order->update([
                'status' => $nextStatus,
                'processed_by' => $request->user()->id,
            ]);

            return $order;
        }, 3);

        return response()->json([
            'status' => 'success',
            'message' => $order->status === 'processing'
                ? 'Pesanan ditandai sedang disiapkan.'
                : 'Pesanan ditandai siap diambil pelanggan di toko.',
            'data' => $order->load(['customer:id,name,phone', 'customerAccount:id,email', 'items']),
        ]);
    }

    /**
     * Hanya dipanggil Owner SETELAH menerima pembayaran (Tunai atau verifikasi mutasi QRIS)
     * dan menyerahkan barang kepada pelanggan.
     */
    public function complete(Request $request, int $id)
    {
        $orderPreview = OnlineOrder::findOrFail($id);
        $isQris = $orderPreview->payment_method === 'qris_gopay';

        $validated = $request->validate($isQris ? [
            'qris_verified_confirmed' => ['required', 'accepted'],
            'goods_handed_over_confirmed' => ['required', 'accepted'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ] : [
            'cash_received_confirmed' => ['required', 'accepted'],
            'goods_handed_over_confirmed' => ['required', 'accepted'],
        ]);

        $order = DB::transaction(function () use ($request, $id, $validated): OnlineOrder {
            $order = OnlineOrder::with('items')->lockForUpdate()->findOrFail($id);
            if ($order->status === 'completed') {
                return $order;
            }
            if (!in_array($order->status, self::ACTIVE_STATUSES, true)) {
                throw ValidationException::withMessages(['order' => 'Pesanan ini sudah dibatalkan.']);
            }

            $isQrisOrder = $order->payment_method === 'qris_gopay';
            $finalReference = $isQrisOrder
                ? (trim((string) ($validated['payment_reference'] ?? $order->payment_reference ?? '')) ?: null)
                : null;

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
                'payment_method' => $isQrisOrder ? 'qris_gopay' : 'cash',
                'change_amount' => '0.00',
                'discount_amount' => '0.00',
                'payment_status' => 'paid',
                'due_date' => null,
                'qris_verified_by' => $isQrisOrder ? $request->user()->id : null,
                'qris_verified_at' => $isQrisOrder ? now() : null,
                'payment_reference' => $finalReference,
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
                'payment_reference' => $finalReference ?? $order->payment_reference,
                'transaction_id' => $transaction->id,
                'processed_by' => $request->user()->id,
                'completed_at' => now(),
            ]);

            app(LoyaltyPointService::class)->awardForPaidTransaction(
                $transaction,
                $request->user()->id,
                $order->id
            );

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
            if (!in_array($order->status, self::ACTIVE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'order' => 'Hanya pesanan yang belum selesai dapat dibatalkan.',
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