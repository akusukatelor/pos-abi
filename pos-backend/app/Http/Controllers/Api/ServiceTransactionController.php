<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceMarginLog;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServiceTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_type_id' => ['nullable', 'integer', 'exists:service_types,id'],
            'status' => ['nullable', 'in:completed,cancelled'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (!empty($validated['from']) && !empty($validated['to']) && $validated['to'] < $validated['from']) {
            throw ValidationException::withMessages([
                'to' => 'Tanggal akhir tidak boleh lebih awal dari tanggal awal.',
            ]);
        }

        $query = ServiceTransaction::query()
            ->with([
                'serviceType:id,name',
                'user:id,name',
                'marginVerifier:id,name',
                'canceller:id,name',
            ])
            ->withCount('marginLogs')
            ->latest('service_at');

        if (!$request->user()->isOwner()) {
            $query->where('user_id', $request->user()->id);
        }

        if (!empty($validated['service_type_id'])) {
            $query->where('service_type_id', (int) $validated['service_type_id']);
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['from'])) {
            $query->whereDate('service_at', '>=', $validated['from']);
        }

        if (!empty($validated['to'])) {
            $query->whereDate('service_at', '<=', $validated['to']);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate(25),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'uuid'],
            'service_type_id' => ['required', 'integer', 'exists:service_types,id'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'service_at' => ['nullable', 'date'],
            'nominal_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'margin_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payloadHash = $this->makePayloadHash($validated);
        $existing = ServiceTransaction::query()
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existing) {
            return $this->respondForIdempotencyReplay($existing, $payloadHash, $request->user()->id);
        }

        try {
            $serviceTransaction = DB::transaction(function () use ($validated, $payloadHash, $request): ServiceTransaction {
                $serviceType = ServiceType::query()
                    ->whereKey($validated['service_type_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$serviceType) {
                    throw ValidationException::withMessages([
                        'service_type_id' => 'Jenis layanan tidak ditemukan atau sudah dinonaktifkan.',
                    ]);
                }

                $serviceTransaction = ServiceTransaction::create([
                    'service_type_id' => $serviceType->id,
                    'user_id' => $request->user()->id,
                    'reference_number' => $validated['reference_number'] ?? null,
                    'service_at' => $validated['service_at'] ?? now(),
                    'nominal_amount' => $validated['nominal_amount'] ?? null,
                    // Margin awal boleh dicatat, tetapi belum masuk laporan sampai diverifikasi Owner.
                    'margin_amount' => $validated['margin_amount'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'status' => 'completed',
                    'idempotency_key' => $validated['idempotency_key'],
                    'idempotency_hash' => $payloadHash,
                ]);

                return $this->loadServiceTransaction($serviceTransaction);
            }, 3);

            return response()->json([
                'status' => 'success',
                'message' => 'Transaksi jasa berhasil dicatat. Margin yang belum diverifikasi tidak dihitung sebagai pendapatan jasa.',
                'data' => $serviceTransaction,
                'meta' => ['idempotent_replay' => false],
            ], 201);
        } catch (QueryException $exception) {
            $existing = ServiceTransaction::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if ($existing) {
                return $this->respondForIdempotencyReplay($existing, $payloadHash, $request->user()->id);
            }

            throw $exception;
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $serviceTransaction = ServiceTransaction::query()->find($id);

        if (!$serviceTransaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi jasa tidak ditemukan.',
            ], 404);
        }

        if (!$request->user()->isOwner() && (int) $serviceTransaction->user_id !== (int) $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi jasa tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->loadServiceTransaction($serviceTransaction),
        ]);
    }

    public function verifyMargin(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'margin_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $serviceTransaction = DB::transaction(function () use ($request, $id, $validated): ServiceTransaction {
            $serviceTransaction = ServiceTransaction::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($serviceTransaction->status !== 'completed') {
                throw ValidationException::withMessages([
                    'status' => 'Margin hanya dapat diverifikasi untuk transaksi jasa yang masih berstatus selesai.',
                ]);
            }

            $newMargin = number_format((float) $validated['margin_amount'], 2, '.', '');
            $oldMargin = $serviceTransaction->margin_amount === null
                ? null
                : number_format((float) $serviceTransaction->margin_amount, 2, '.', '');

            $alreadyVerified = $serviceTransaction->margin_verified_by !== null;
            $isSameAmount = $oldMargin !== null && $oldMargin === $newMargin;

            if ($alreadyVerified && $isSameAmount) {
                return $this->loadServiceTransaction($serviceTransaction);
            }

            if ($alreadyVerified && !$isSameAmount && blank($validated['note'] ?? null)) {
                throw ValidationException::withMessages([
                    'note' => 'Catatan wajib diisi ketika mengubah margin yang sudah diverifikasi.',
                ]);
            }

            ServiceMarginLog::create([
                'service_transaction_id' => $serviceTransaction->id,
                'changed_by' => $request->user()->id,
                'old_margin_amount' => $oldMargin,
                'new_margin_amount' => $newMargin,
                'note' => $validated['note'] ?? null,
            ]);

            $serviceTransaction->update([
                'margin_amount' => $newMargin,
                'margin_verified_by' => $request->user()->id,
                'margin_verified_at' => now(),
            ]);

            return $this->loadServiceTransaction($serviceTransaction->refresh());
        }, 3);

        return response()->json([
            'status' => 'success',
            'message' => 'Margin jasa berhasil diverifikasi dan dicatat dalam histori.',
            'data' => $serviceTransaction,
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $serviceTransaction = DB::transaction(function () use ($request, $id, $validated): ServiceTransaction {
            $serviceTransaction = ServiceTransaction::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($serviceTransaction->status === 'cancelled') {
                return $this->loadServiceTransaction($serviceTransaction);
            }

            if ($serviceTransaction->status !== 'completed') {
                throw ValidationException::withMessages([
                    'status' => 'Transaksi jasa tidak dapat dibatalkan dari status saat ini.',
                ]);
            }

            $serviceTransaction->update([
                'status' => 'cancelled',
                'cancelled_by' => $request->user()->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $validated['cancellation_reason'],
            ]);

            return $this->loadServiceTransaction($serviceTransaction->refresh());
        }, 3);

        return response()->json([
            'status' => 'success',
            'message' => 'Pencatatan jasa dibatalkan. Riwayat tetap disimpan dan transaksi dikeluarkan dari laporan aktif.',
            'data' => $serviceTransaction,
        ]);
    }

    private function makePayloadHash(array $validated): string
    {
        return hash('sha256', json_encode([
            'service_type_id' => (int) $validated['service_type_id'],
            'reference_number' => $validated['reference_number'] ?? null,
            'service_at' => isset($validated['service_at']) ? date('Y-m-d H:i:s', strtotime($validated['service_at'])) : null,
            'nominal_amount' => isset($validated['nominal_amount']) ? number_format((float) $validated['nominal_amount'], 2, '.', '') : null,
            'margin_amount' => isset($validated['margin_amount']) ? number_format((float) $validated['margin_amount'], 2, '.', '') : null,
            'notes' => $validated['notes'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    private function respondForIdempotencyReplay(ServiceTransaction $serviceTransaction, string $payloadHash, int $userId): JsonResponse
    {
        if ((int) $serviceTransaction->user_id !== $userId || !hash_equals((string) $serviceTransaction->idempotency_hash, $payloadHash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Idempotency key sudah digunakan untuk transaksi jasa atau data yang berbeda.',
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Transaksi jasa ini sudah pernah dicatat; data lama dikembalikan.',
            'data' => $this->loadServiceTransaction($serviceTransaction),
            'meta' => ['idempotent_replay' => true],
        ]);
    }

    private function loadServiceTransaction(ServiceTransaction $serviceTransaction): ServiceTransaction
    {
        return $serviceTransaction->load([
            'serviceType:id,name',
            'user:id,name',
            'marginVerifier:id,name',
            'canceller:id,name',
            'marginLogs.changedBy:id,name',
        ]);
    }
}
