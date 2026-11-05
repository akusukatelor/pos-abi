<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockOpnameController extends Controller
{
    public function index(): JsonResponse
    {
        $opnames = StockOpname::query()
            ->with(['creator:id,name', 'approver:id,name', 'rejector:id,name'])
            ->withCount('items')
            ->latest('counted_at')
            ->paginate(25);

        return response()->json([
            'status' => 'success',
            'data' => $opnames,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'uuid'],
            'counted_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.physical_stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $items = collect($validated['items'])
            ->map(fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'physical_stock' => (int) $item['physical_stock'],
                'notes' => $item['notes'] ?? null,
            ])
            ->sortBy('product_id')
            ->values();

        $payloadHash = $this->makePayloadHash($validated, $items);
        $existing = StockOpname::query()
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existing) {
            return $this->respondForIdempotencyReplay($existing, $payloadHash, $request->user()->id);
        }

        try {
            $opname = DB::transaction(function () use ($validated, $items, $payloadHash, $request): StockOpname {
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

                $opnameNumber = 'OPN-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
                $opname = StockOpname::create([
                    'opname_number' => $opnameNumber,
                    'counted_at' => $validated['counted_at'],
                    'snapshot_at' => now(),
                    'status' => 'pending_approval',
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => $request->user()->id,
                    'idempotency_key' => $validated['idempotency_key'],
                    'idempotency_hash' => $payloadHash,
                ]);

                foreach ($items as $item) {
                    /** @var Product $product */
                    $product = $products->get($item['product_id']);
                    $difference = $item['physical_stock'] - $product->stock;

                    if ($difference !== 0 && blank($item['notes']) && blank($validated['notes'] ?? null)) {
                        throw ValidationException::withMessages([
                            'items' => "Alasan selisih stok untuk produk {$product->name} wajib diisi.",
                        ]);
                    }

                    $opname->items()->create([
                        'product_id' => $product->id,
                        'system_stock' => $product->stock,
                        'physical_stock' => $item['physical_stock'],
                        'difference' => $difference,
                        'notes' => $item['notes'],
                    ]);
                }

                return $this->loadOpname($opname);
            }, 3);

            return response()->json([
                'status' => 'success',
                'message' => 'Hasil stock opname berhasil dicatat dan menunggu persetujuan Owner.',
                'data' => $opname,
                'meta' => ['idempotent_replay' => false],
            ], 201);
        } catch (QueryException $exception) {
            $existing = StockOpname::query()
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
        $opname = StockOpname::query()->find($id);

        if (!$opname) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dokumen stock opname tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->loadOpname($opname),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $id): array {
            $opname = StockOpname::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($opname->status === 'approved') {
                return [
                    'opname' => $this->loadOpname($opname),
                    'replayed' => true,
                ];
            }

            if ($opname->status !== 'pending_approval') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya stock opname yang menunggu persetujuan dapat disetujui.',
                ]);
            }

            $items = $opname->items()->orderBy('product_id')->get();
            $products = Product::query()
                ->whereIn('id', $items->pluck('product_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                /** @var Product|null $product */
                $product = $products->get($item->product_id);

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => "Produk ID {$item->product_id} tidak lagi tersedia.",
                    ]);
                }

                if ($product->stock !== $item->system_stock) {
                    abort(409, "Stok produk {$product->name} berubah setelah penghitungan. Buat stock opname baru agar penyesuaian tidak memakai data kedaluwarsa.");
                }
            }

            foreach ($items as $item) {
                $difference = $item->difference;

                if ($difference === 0) {
                    continue;
                }

                /** @var Product $product */
                $product = $products->get($item->product_id);
                $product->stock = $item->physical_stock;
                $product->save();

                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'stock_opname_item_id' => $item->id,
                    'type' => $difference > 0 ? 'in' : 'out',
                    'quantity' => abs($difference),
                    'description' => 'Penyesuaian stock opname '.$opname->opname_number,
                ]);
            }

            $opname->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return [
                'opname' => $this->loadOpname($opname->refresh()),
                'replayed' => false,
            ];
        }, 3);

        return response()->json([
            'status' => 'success',
            'message' => $result['replayed']
                ? 'Stock opname sudah disetujui sebelumnya; stok tidak disesuaikan kembali.'
                : 'Stock opname disetujui dan penyesuaian stok berhasil dicatat.',
            'data' => $result['opname'],
            'meta' => ['idempotent_replay' => $result['replayed']],
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $opname = DB::transaction(function () use ($request, $id, $validated): StockOpname {
            $opname = StockOpname::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($opname->status !== 'pending_approval') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya stock opname yang menunggu persetujuan dapat ditolak.',
                ]);
            }

            $opname->update([
                'status' => 'rejected',
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            return $this->loadOpname($opname->refresh());
        }, 3);

        return response()->json([
            'status' => 'success',
            'message' => 'Stock opname ditolak. Tidak ada perubahan pada stok.',
            'data' => $opname,
        ]);
    }

    private function makePayloadHash(array $validated, Collection $items): string
    {
        return hash('sha256', json_encode([
            'counted_at' => date('Y-m-d H:i:s', strtotime($validated['counted_at'])),
            'notes' => $validated['notes'] ?? null,
            'items' => $items->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function respondForIdempotencyReplay(StockOpname $opname, string $payloadHash, int $userId): JsonResponse
    {
        if ((int) $opname->created_by !== $userId || !hash_equals((string) $opname->idempotency_hash, $payloadHash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Idempotency key sudah digunakan untuk stock opname atau data yang berbeda.',
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Stock opname ini sudah pernah dicatat; data lama dikembalikan.',
            'data' => $this->loadOpname($opname),
            'meta' => ['idempotent_replay' => true],
        ]);
    }

    private function loadOpname(StockOpname $opname): StockOpname
    {
        return $opname->load([
            'items.product:id,name,sku_code,unit_id',
            'creator:id,name',
            'approver:id,name',
            'rejector:id,name',
        ]);
    }
}
