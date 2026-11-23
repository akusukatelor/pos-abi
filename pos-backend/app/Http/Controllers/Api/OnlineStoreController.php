<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnlineStoreController extends Controller
{
    public function catalog(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Product::query()->where('is_active', true)->orderBy('name')->orderBy('id');
        if (!empty($data['search'])) {
            $escaped = addcslashes(trim($data['search']), '%_\\');
            $query->where('name', 'like', '%'.$escaped.'%');
        }
        $products = $query->paginate(24);

        return response()->json([
            'status' => 'success',
            'data' => $products->getCollection()->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->sell_price,
                'available' => $product->stock > 0,
            ])->values(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function placeOrder(Request $request)
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $items = collect($data['items'])->map(fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) $item['quantity'],
        ])->sortBy('product_id')->values();
        $hash = hash('sha256', json_encode($items->all(), JSON_THROW_ON_ERROR));
        $account = $request->user();

        $existing = OnlineOrder::with('items')->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return $this->replay($existing, $account->id, $hash);
        }

        try {
            $order = DB::transaction(function () use ($account, $items, $hash, $data): OnlineOrder {
                $totalCents = 0;
                $lines = [];
                foreach ($items as $item) {
                    $product = Product::query()->whereKey($item['product_id'])
                        ->where('is_active', true)->lockForUpdate()->first();
                    if (!$product || $product->stock < $item['quantity']) {
                        throw ValidationException::withMessages([
                            'items' => 'Produk tidak tersedia atau jumlah pesanan melebihi stok. Perbarui keranjang.',
                        ]);
                    }
                    $priceCents = (int) round((float) $product->sell_price * 100);
                    $lineCents = $priceCents * $item['quantity'];
                    $totalCents += $lineCents;
                    $lines[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity' => $item['quantity'],
                        'unit_price' => number_format($priceCents / 100, 2, '.', ''),
                        'subtotal' => number_format($lineCents / 100, 2, '.', ''),
                    ];
                }

                $order = OnlineOrder::create([
                    'customer_account_id' => $account->id,
                    'customer_id' => $account->customer_id,
                    'order_number' => 'ON-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),
                    'status' => 'pending',
                    'payment_method' => 'cash_on_pickup',
                    'payment_status' => 'unpaid',
                    'total_amount' => number_format($totalCents / 100, 2, '.', ''),
                    'idempotency_key' => $data['idempotency_key'],
                    'idempotency_hash' => $hash,
                ]);
                $order->items()->createMany($lines);
                return $order->load('items');
            }, 3);

            return response()->json([
                'status' => 'success',
                'message' => 'Pesanan diterima. Bayar tunai ketika mengambil barang di toko.',
                'data' => $order,
                'meta' => ['idempotent_replay' => false],
            ], 201);
        } catch (QueryException $e) {
            $existing = OnlineOrder::with('items')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->replay($existing, $account->id, $hash);
            }
            throw $e;
        }
    }

    public function myOrders(Request $request)
    {
        $orders = OnlineOrder::with('items')
            ->where('customer_account_id', $request->user()->id)
            ->latest()->paginate(20);

        return response()->json(['status' => 'success', 'data' => $orders]);
    }

    public function showMyOrder(Request $request, int $id)
    {
        $order = OnlineOrder::with('items')
            ->where('customer_account_id', $request->user()->id)
            ->findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $order]);
    }

    public function loyalty(Request $request)
    {
        $account = $request->user();
        $entries = $account->loyaltyEntries()->latest()->limit(50)->get([
            'id', 'points_delta', 'description', 'created_at',
        ]);
        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled' => false,
                'points_balance' => (int) $account->loyaltyEntries()->sum('points_delta'),
                'entries' => $entries,
                'message' => 'Aturan perolehan dan penukaran poin menunggu persetujuan toko.',
            ],
        ]);
    }

    private function replay(OnlineOrder $order, int $accountId, string $hash)
    {
        if ($order->customer_account_id !== $accountId || !hash_equals($order->idempotency_hash, $hash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kunci permintaan sudah digunakan untuk pesanan berbeda.',
            ], 409);
        }
        return response()->json([
            'status' => 'success',
            'data' => $order,
            'meta' => ['idempotent_replay' => true],
        ]);
    }
}
