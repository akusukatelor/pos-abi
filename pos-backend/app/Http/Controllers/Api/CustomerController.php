<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDebtPayment;
use App\Models\Transaction;
use App\Services\LoyaltyPointService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index()
    {
        $customers = Customer::query()
            ->with(['account'])
            ->withSum('loyaltyEntries as points_balance', 'points_delta')
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $customers,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
        ]);

        $customer = Customer::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'total_debt' => 0,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pelanggan berhasil ditambahkan.',
            'data' => $customer,
        ], 201);
    }

    public function show($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pelanggan tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->buildCustomerDetail($customer),
        ]);
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pelanggan tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
        ]);

        $customer->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Data pelanggan berhasil diperbarui.',
            'data' => $customer,
        ]);
    }

    public function destroy($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pelanggan tidak ditemukan.',
            ], 404);
        }

        if ((float) $customer->total_debt > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pelanggan masih memiliki piutang yang belum lunas.',
            ], 422);
        }

        if ($customer->account()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pelanggan memiliki akun online. Nonaktifkan akun, bukan menghapus data pelanggan.',
            ], 409);
        }

        if ($customer->transactions()->exists() || $customer->debtPayments()->exists() || $customer->loyaltyEntries()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pelanggan memiliki histori transaksi, pembayaran, atau poin loyalitas, tidak dapat dihapus.',
            ], 409);
        }

        $customer->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Pelanggan berhasil dihapus.',
        ]);
    }

    public function payDebt(Request $request, $id)
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'payment_method' => ['required', 'in:cash'],
            'transaction_id' => ['nullable', 'integer', 'exists:transactions,id'],
        ]);

        $amount = number_format((float) $validated['amount'], 2, '.', '');
        $hash = hash('sha256', json_encode([
            'customer_id' => (int) $id,
            'transaction_id' => isset($validated['transaction_id']) ? (int) $validated['transaction_id'] : null,
            'amount' => $amount,
            'payment_method' => $validated['payment_method'],
        ], JSON_THROW_ON_ERROR));

        try {
            [$customer, $payment, $replayed] = DB::transaction(function () use ($id, $validated, $amount, $hash, $request): array {
                $customer = Customer::query()->whereKey($id)->lockForUpdate()->first();
                if (!$customer) {
                    throw ValidationException::withMessages([
                        'customer' => 'Pelanggan tidak ditemukan.',
                    ]);
                }

                $existing = CustomerDebtPayment::query()
                    ->where('idempotency_key', $validated['idempotency_key'])
                    ->first();
                if ($existing) {
                    return [$customer, $existing, true];
                }

                $amountCents = (int) round(((float) $amount) * 100);
                $debtCents = (int) round(((float) $customer->total_debt) * 100);

                $invoices = Transaction::query()
                    ->where('customer_id', $customer->id)
                    ->where('payment_method', 'debt')
                    ->where('payment_status', 'unpaid')
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $outstandingCents = 0;
                foreach ($invoices as $invoice) {
                    $total = (int) round(((float) $invoice->total_price) * 100);
                    $paid = (int) round(((float) $invoice->pay_amount) * 100);
                    if ($paid < 0 || $paid >= $total) {
                        throw ValidationException::withMessages([
                            'amount' => 'Status invoice Kasbon tidak konsisten. Minta Owner audit data dahulu.',
                        ]);
                    }
                    $outstandingCents += $total - $paid;
                }

                if ($debtCents !== $outstandingCents) {
                    throw ValidationException::withMessages([
                        'amount' => 'Saldo piutang dan sisa invoice berbeda. Periksa data historis sebelum menerima pembayaran.',
                    ]);
                }
                if ($amountCents > $debtCents) {
                    throw ValidationException::withMessages([
                        'amount' => 'Nominal pembayaran melebihi total piutang pelanggan.',
                    ]);
                }

                if (isset($validated['transaction_id'])) {
                    $invoices = $invoices->where('id', (int) $validated['transaction_id'])->values();
                    if ($invoices->isEmpty()) {
                        throw ValidationException::withMessages([
                            'transaction_id' => 'Invoice bukan Kasbon aktif milik pelanggan ini.',
                        ]);
                    }
                    $invoice = $invoices->first();
                    $remaining = (int) round(((float) $invoice->total_price - (float) $invoice->pay_amount) * 100);
                    if ($amountCents > $remaining) {
                        throw ValidationException::withMessages([
                            'amount' => 'Nominal melebihi sisa tagihan invoice tersebut.',
                        ]);
                    }
                }

                $payment = CustomerDebtPayment::create([
                    'customer_id' => $customer->id,
                    'user_id' => $request->user()->id,
                    'amount' => $amount,
                    'payment_method' => 'cash',
                    'idempotency_key' => $validated['idempotency_key'],
                    'idempotency_hash' => $hash,
                ]);

                $loyaltyService = app(LoyaltyPointService::class);
                $toAllocate = $amountCents;

                foreach ($invoices as $invoice) {
                    if ($toAllocate === 0) {
                        break;
                    }

                    $total = (int) round(((float) $invoice->total_price) * 100);
                    $paid = (int) round(((float) $invoice->pay_amount) * 100);
                    $allocated = min($toAllocate, $total - $paid);
                    if ($allocated <= 0) {
                        continue;
                    }

                    $invoice->pay_amount = number_format(($paid + $allocated) / 100, 2, '.', '');
                    if ($paid + $allocated === $total) {
                        $invoice->payment_status = 'paid';
                    }
                    $invoice->save();

                    if ($invoice->payment_status === 'paid') {
                        $loyaltyService->awardForPaidTransaction($invoice, $request->user()->id);
                    }

                    $payment->allocations()->create([
                        'transaction_id' => $invoice->id,
                        'amount' => number_format($allocated / 100, 2, '.', ''),
                    ]);
                    $toAllocate -= $allocated;
                }

                if ($toAllocate !== 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'Alokasi pembayaran tidak lengkap; perubahan dibatalkan.',
                    ]);
                }

                $customer->total_debt = number_format(($debtCents - $amountCents) / 100, 2, '.', '');
                $customer->save();

                return [$customer, $payment, false];
            }, 3);
        } catch (QueryException $exception) {
            $existing = CustomerDebtPayment::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();
            if (!$existing) {
                throw $exception;
            }

            $customer = Customer::findOrFail($id);
            $payment = $existing;
            $replayed = true;
        }

        if ($payment->user_id !== $request->user()->id
            || $payment->customer_id !== (int) $id
            || !hash_equals((string) $payment->idempotency_hash, $hash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Idempotency key pernah dipakai untuk pembayaran atau pengguna lain.',
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'message' => $replayed
                ? 'Pembayaran ini telah dicatat sebelumnya; tidak dibukukan ulang.'
                : 'Pembayaran piutang berhasil dicatat.',
            'data' => $this->buildCustomerDetail($customer),
            'payment' => $payment->load(['user:id,name', 'allocations.transaction:id,invoice_number']),
            'meta' => ['idempotent_replay' => $replayed],
        ], $replayed ? 200 : 201);
    }

    public function redeemLoyaltyPoints(Request $request, $id, LoyaltyPointService $loyaltyService)
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'uuid'],
            'points' => ['required', 'integer', 'min:1', 'max:100000'],
            'description' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pelanggan tidak ditemukan.',
            ], 404);
        }

        $result = $loyaltyService->redeemPoints(
            $customer,
            (int) $validated['points'],
            $validated['description'],
            $request->user()->id,
            $validated['idempotency_key']
        );

        return response()->json([
            'status' => 'success',
            'message' => $result['replayed']
                ? 'Penukaran poin ini sudah dicatat sebelumnya.'
                : "Penukaran {$validated['points']} poin berhasil dicatat.",
            'data' => $this->buildCustomerDetail($customer),
            'meta' => ['idempotent_replay' => $result['replayed']],
        ], $result['replayed'] ? 200 : 201);
    }

    private function buildCustomerDetail(Customer $customer): array
    {
        $loaded = $this->withFinancialHistory($customer);
        $account = $customer->account;
        $pointsBalance = (int) $customer->loyaltyEntries()->sum('points_delta');
        $payload = $loaded->toArray();

        $payload['points_balance'] = $pointsBalance;
        $payload['loyalty_enabled'] = true;
        $payload['rupiah_per_point'] = LoyaltyPointService::RUPIAH_PER_POINT;
        $payload['redeem_value_per_point'] = LoyaltyPointService::RUPIAH_VALUE_PER_POINT;

        $payload['online_account'] = $account ? [
            'id' => $account->id,
            'email' => $account->email,
            'is_active' => (bool) $account->is_active,
            'points_balance' => $pointsBalance,
            'loyalty_enabled' => true,
            'rupiah_per_point' => LoyaltyPointService::RUPIAH_PER_POINT,
            'redeem_value_per_point' => LoyaltyPointService::RUPIAH_VALUE_PER_POINT,
        ] : null;

        $payload['loyalty_entries'] = $customer->loyaltyEntries()
            ->with('creator:id,name')
            ->latest()
            ->limit(30)
            ->get()
            ->toArray();

        return $payload;
    }

    private function withFinancialHistory(Customer $customer): Customer
    {
        return $customer->load([
            'transactions' => fn ($query) => $query->latest(),
            'debtPayments' => fn ($query) => $query
                ->with(['user:id,name', 'allocations.transaction:id,invoice_number'])
                ->latest(),
        ]);
    }
}