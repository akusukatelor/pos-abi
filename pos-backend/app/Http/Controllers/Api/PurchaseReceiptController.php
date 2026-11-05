<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptController extends Controller
{
    public function index(): JsonResponse
    {
        $receipts = PurchaseReceipt::query()
            ->with([
                'supplier:id,name',
                'items.product:id,name,sku_code,unit_id',
                'creator:id,name',
                'confirmer:id,name',
            ])
            ->latest('received_at')
            ->paginate(25);

        return response()->json([
            'status' => 'success',
            'data' => $receipts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'uuid'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'received_at' => ['required', 'date'],
            'supplier_invoice_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.unit_cost' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
        ]);

        $items = collect($validated['items'])
            ->map(fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
                'unit_cost_cents' => (int) round(((float) $item['unit_cost']) * 100),
            ])
            ->sortBy('product_id')
            ->values();

        $payloadHash = $this->makePayloadHash($validated, $items);
        $existing = PurchaseReceipt::query()
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existing) {
            return $this->respondForIdempotencyReplay($existing, $payloadHash, $request->user()->id);
        }

        try {
            $receipt = DB::transaction(function () use ($validated, $items, $payloadHash, $request): PurchaseReceipt {
                $supplier = Supplier::query()
                    ->whereKey($validated['supplier_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$supplier) {
                    throw ValidationException::withMessages([
                        'supplier_id' => 'Supplier tidak ditemukan.',
                    ]);
                }

                $products = Product::query()
                    ->whereIn('id', $items->pluck('product_id'))
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($products->count() !== $items->count()) {
                    throw ValidationException::withMessages([
                        'items' => 'Satu atau lebih produk tidak ditemukan atau sudah dinonaktifkan.',
                    ]);
                }

                $totalCents = 0;
                $lineTotalsCents = [];
                $maximumTotalCents = 999999999999;

                foreach ($items as $item) {
                    if ($item['unit_cost_cents'] > intdiv($maximumTotalCents, $item['quantity'])) {
                        throw ValidationException::withMessages([
                            'items' => 'Total salah satu item melebihi batas nominal yang dapat disimpan.',
                        ]);
                    }

                    $lineTotalCents = $item['unit_cost_cents'] * $item['quantity'];

                    if ($totalCents > $maximumTotalCents - $lineTotalCents) {
                        throw ValidationException::withMessages([
                            'items' => 'Total pembelian melebihi batas nominal yang dapat disimpan.',
                        ]);
                    }

                    $lineTotalsCents[$item['product_id']] = $lineTotalCents;
                    $totalCents += $lineTotalCents;
                }

                $receiptNumber = 'PR-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));

                $receipt = PurchaseReceipt::create([
                    'receipt_number' => $receiptNumber,
                    'supplier_id' => $validated['supplier_id'],
                    'received_at' => $validated['received_at'],
                    'supplier_invoice_reference' => $validated['supplier_invoice_reference'] ?? null,
                    'status' => 'draft',
                    'payment_status' => null,
                    'total_amount' => number_format($totalCents / 100, 2, '.', ''),
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => $request->user()->id,
                    'idempotency_key' => $validated['idempotency_key'],
                    'idempotency_hash' => $payloadHash,
                ]);

                foreach ($items as $item) {
                    $lineTotalCents = $lineTotalsCents[$item['product_id']];

                    $receipt->items()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_cost' => number_format($item['unit_cost_cents'] / 100, 2, '.', ''),
                        'line_total' => number_format($lineTotalCents / 100, 2, '.', ''),
                    ]);
                }

                return $this->loadReceipt($receipt);
            }, 3);

            return response()->json([
                'status' => 'success',
                'message' => 'Draft penerimaan supplier berhasil dicatat. Konfirmasikan setelah barang dan pembayaran diperiksa.',
                'data' => $receipt,
                'meta' => ['idempotent_replay' => false],
            ], 201);
        } catch (QueryException $exception) {
            $existing = PurchaseReceipt::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if ($existing) {
                return $this->respondForIdempotencyReplay($existing, $payloadHash, $request->user()->id);
            }

            throw $exception;
        }
    }

    public function show(int $id): JsonResponse
    {
        $receipt = PurchaseReceipt::query()->find($id);

        if (!$receipt) {
            return response()->json([
                'status' => 'error',
                'message' => 'Penerimaan supplier tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->loadReceipt($receipt),
        ]);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'received_and_paid_confirmed' => ['required', 'accepted'],
        ]);

        $result = DB::transaction(function () use ($request, $id): array {
            $receipt = PurchaseReceipt::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->status === 'confirmed') {
                return [
                    'receipt' => $this->loadReceipt($receipt),
                    'replayed' => true,
                ];
            }

            if ($receipt->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya draft penerimaan yang dapat dikonfirmasi.',
                ]);
            }

            $items = $receipt->items()->orderBy('product_id')->get();
            $products = Product::query()
                ->whereIn('id', $items->pluck('product_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $items->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Produk pada penerimaan tidak lagi tersedia.',
                ]);
            }

            foreach ($items as $item) {
                /** @var Product|null $product */
                $product = $products->get($item->product_id);

                if (!$product || !$product->is_active) {
                    throw ValidationException::withMessages([
                        'items' => "Produk ID {$item->product_id} sudah dinonaktifkan dan tidak dapat diterima.",
                    ]);
                }

                if ($product->stock + $item->quantity > 2147483647) {
                    throw ValidationException::withMessages([
                        'items' => "Penambahan stok {$product->name} melebihi kapasitas kolom stok.",
                    ]);
                }

                $product->stock += $item->quantity;
                $product->save();

                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'supplier_id' => $receipt->supplier_id,
                    'purchase_receipt_item_id' => $item->id,
                    'type' => 'in',
                    'quantity' => $item->quantity,
                    'description' => 'Penerimaan supplier '.$receipt->receipt_number,
                ]);
            }

            $receipt->update([
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);

            return [
                'receipt' => $this->loadReceipt($receipt->refresh()),
                'replayed' => false,
            ];
        }, 3);

        return response()->json([
            'status' => 'success',
            'message' => $result['replayed']
                ? 'Penerimaan ini sudah pernah dikonfirmasi; stok tidak ditambahkan kembali.'
                : 'Penerimaan supplier berhasil dikonfirmasi dan stok telah diperbarui.',
            'data' => $result['receipt'],
            'meta' => ['idempotent_replay' => $result['replayed']],
        ], 200);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $id): array {
            $receipt = PurchaseReceipt::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (!$receipt) {
                return ['status' => 404, 'message' => 'Penerimaan supplier tidak ditemukan.'];
            }

            if (!$request->user()->isOwner() && (int) $receipt->created_by !== (int) $request->user()->id) {
                return ['status' => 403, 'message' => 'Anda hanya dapat menghapus draft penerimaan yang Anda buat sendiri.'];
            }

            if ($receipt->status !== 'draft') {
                return ['status' => 409, 'message' => 'Penerimaan yang sudah dikonfirmasi tidak dapat dihapus agar histori stok tetap terjaga.'];
            }

            $receipt->delete();

            return ['status' => 200, 'message' => 'Draft penerimaan supplier berhasil dihapus.'];
        }, 3);

        return response()->json([
            'status' => $result['status'] === 200 ? 'success' : 'error',
            'message' => $result['message'],
        ], $result['status']);
    }

    private function makePayloadHash(array $validated, Collection $items): string
    {
        return hash('sha256', json_encode([
            'supplier_id' => (int) $validated['supplier_id'],
            'received_at' => date('Y-m-d', strtotime($validated['received_at'])),
            'supplier_invoice_reference' => $validated['supplier_invoice_reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'items' => $items->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function respondForIdempotencyReplay(PurchaseReceipt $receipt, string $payloadHash, int $userId): JsonResponse
    {
        if ((int) $receipt->created_by !== $userId || !hash_equals((string) $receipt->idempotency_hash, $payloadHash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Idempotency key sudah digunakan untuk penerimaan atau data yang berbeda.',
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Penerimaan ini sudah pernah dicatat; data yang sama dikembalikan.',
            'data' => $this->loadReceipt($receipt),
            'meta' => ['idempotent_replay' => true],
        ]);
    }

    private function loadReceipt(PurchaseReceipt $receipt): PurchaseReceipt
    {
        return $receipt->load([
            'supplier:id,name',
            'items.product:id,name,sku_code,unit_id',
            'creator:id,name',
            'confirmer:id,name',
        ]);
    }
}
