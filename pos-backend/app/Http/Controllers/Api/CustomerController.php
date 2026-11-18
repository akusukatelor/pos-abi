<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index()
    {
        $customers = Customer::query()->latest()->get();

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
        $customer = Customer::with([
            'transactions' => fn ($query) => $query->latest(),
        ])->find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pelanggan tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $customer,
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

        $customer->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Pelanggan berhasil dihapus.',
        ]);
    }

    /**
     * Mencatat pembayaran/pelunasan piutang pelanggan.
     */
    public function payDebt(Request $request, $id)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'transaction_id' => ['nullable', 'integer', 'exists:transactions,id'],
        ], [
            'amount.required' => 'Nominal pembayaran wajib diisi.',
            'amount.min' => 'Nominal pembayaran minimal Rp 1.',
        ]);

        $updatedCustomer = DB::transaction(function () use ($validated, $id): Customer {
            $customer = Customer::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (!$customer) {
                throw ValidationException::withMessages([
                    'customer' => 'Pelanggan tidak ditemukan.',
                ]);
            }

            $currentDebtCents = (int) round(((float) $customer->total_debt) * 100);
            $payCents = (int) round(((float) $validated['amount']) * 100);

            if ($currentDebtCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Pelanggan ini tidak memiliki piutang berjalan.',
                ]);
            }

            if ($payCents > $currentDebtCents) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran melebihi total piutang pelanggan.',
                ]);
            }

            // Jika membayar untuk 1 transaksi/invoice tertentu
            if (!empty($validated['transaction_id'])) {
                $transaction = Transaction::query()
                    ->where('customer_id', $customer->id)
                    ->whereKey($validated['transaction_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$transaction || $transaction->payment_status === 'paid') {
                    throw ValidationException::withMessages([
                        'transaction_id' => 'Transaksi tidak ditemukan atau sudah lunas.',
                    ]);
                }

                $txTotalCents = (int) round(((float) $transaction->total_price) * 100);
                $txPaidCents = (int) round(((float) $transaction->pay_amount) * 100);
                $txRemainingCents = max(0, $txTotalCents - $txPaidCents);

                if ($payCents > $txRemainingCents) {
                    throw ValidationException::withMessages([
                        'amount' => 'Nominal pembayaran melebihi sisa tagihan pada invoice ini.',
                    ]);
                }

                $newPaidCents = $txPaidCents + $payCents;
                $transaction->pay_amount = number_format($newPaidCents / 100, 2, '.', '');

                if ($newPaidCents >= $txTotalCents) {
                    $transaction->payment_status = 'paid';
                }

                $transaction->save();
            } else {
                // Alokasi otomatis (FIFO) ke transaksi yang belum lunas dari yang paling lama
                $unpaidTransactions = Transaction::query()
                    ->where('customer_id', $customer->id)
                    ->where('payment_status', '!=', 'paid')
                    ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                $remainingToAllocateCents = $payCents;

                foreach ($unpaidTransactions as $transaction) {
                    if ($remainingToAllocateCents <= 0) {
                        break;
                    }

                    $txTotalCents = (int) round(((float) $transaction->total_price) * 100);
                    $txPaidCents = (int) round(((float) $transaction->pay_amount) * 100);
                    $txRemainingCents = max(0, $txTotalCents - $txPaidCents);

                    if ($txRemainingCents <= 0) {
                        $transaction->payment_status = 'paid';
                        $transaction->save();
                        continue;
                    }

                    $allocatedCents = min($remainingToAllocateCents, $txRemainingCents);
                    $newPaidCents = $txPaidCents + $allocatedCents;

                    $transaction->pay_amount = number_format($newPaidCents / 100, 2, '.', '');
                    if ($newPaidCents >= $txTotalCents) {
                        $transaction->payment_status = 'paid';
                    }

                    $transaction->save();
                    $remainingToAllocateCents -= $allocatedCents;
                }
            }

            // Kurangi total_debt pelanggan
            $newCustomerDebtCents = max(0, $currentDebtCents - $payCents);
            $customer->total_debt = number_format($newCustomerDebtCents / 100, 2, '.', '');
            $customer->save();

            return $customer->load([
                'transactions' => fn ($query) => $query->latest(),
            ]);
        }, 3);

        return response()->json([
            'status' => 'success',
            'message' => 'Pembayaran piutang berhasil dicatat.',
            'data' => $updatedCustomer,
        ]);
    }
}