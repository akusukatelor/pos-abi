<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    /**
     * Owner dapat melihat seluruh transaksi; Kasir hanya transaksi miliknya.
     */
    public function index(Request $request)
    {
        $query = Transaction::with(['user', 'customer', 'details.product', 'qrisVerifier:id,name'])->latest();

        if (!$request->user()->isOwner()) {
            $query->where('user_id', $request->user()->id);
        }

        $transactions = $query->paginate(25);

        if (!$request->user()->isOwner()) {
            $transactions->getCollection()->each(function (Transaction $transaction): void {
                $transaction->details->each(function (TransactionDetail $detail): void {
                    $detail->product?->makeHidden('buy_price');
                });
            });
        }

        return response()->json([
            'status' => 'success',
            'data' => $transactions,
        ]);
    }

    /**
     * Checkout tunai, QRIS GoPay Merchant, atau Kasbon (debt).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'uuid'],
            'customer_id' => ['nullable', 'required_if:payment_method,debt', 'integer', 'exists:customers,id'],
            'payment_method' => ['required', 'in:cash,qris_gopay,debt'],
            'pay_amount' => ['required', 'numeric', 'min:0'],
            'manual_verification_confirmed' => ['exclude_unless:payment_method,qris_gopay', 'required', 'accepted'],
            'payment_reference' => ['exclude_unless:payment_method,qris_gopay', 'nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'customer_id.required_if' => 'Pelanggan wajib dipilih untuk transaksi kasbon.',
        ]);

        // Gabungkan produk yang sama agar stok tidak diperiksa/diubah berulang.
        $items = collect($validated['items'])
            ->groupBy('product_id')
            ->map(fn (Collection $rows, string $productId): array => [
                'product_id' => (int) $productId,
                'quantity' => (int) $rows->sum('quantity'),
            ])
            ->sortBy('product_id')
            ->values();

        $payloadHash = hash('sha256', json_encode([
            'customer_id' => $validated['customer_id'] ?? null,
            'payment_method' => $validated['payment_method'],
            'pay_amount' => number_format((float) $validated['pay_amount'], 2, '.', ''),
            'manual_verification_confirmed' => (bool) ($validated['manual_verification_confirmed'] ?? false),
            'payment_reference' => $validated['payment_reference'] ?? null,
            'items' => $items->all(),
        ], JSON_THROW_ON_ERROR));

        $existing = Transaction::with(['details.product', 'customer', 'user', 'qrisVerifier:id,name'])
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existing) {
            return $this->respondForIdempotencyReplay($existing, $payloadHash, $request->user()->id, !$request->user()->isOwner());
        }

        try {
            $transaction = DB::transaction(function () use ($validated, $items, $payloadHash, $request): Transaction {
                $lockedProducts = [];
                $totalCents = 0;

                foreach ($items as $item) {
                    $product = Product::query()
                        ->whereKey($item['product_id'])
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->first();

                    if (!$product) {
                        throw ValidationException::withMessages([
                            'items' => "Produk ID {$item['product_id']} tidak ditemukan atau sudah dinonaktifkan.",
                        ]);
                    }

                    if ($product->stock < $item['quantity']) {
                        throw ValidationException::withMessages([
                            'items' => "Stok {$product->name} tidak mencukupi. Stok tersedia: {$product->stock}.",
                        ]);
                    }

                    $unitPriceCents = (int) round(((float) $product->sell_price) * 100);
                    $subtotalCents = $unitPriceCents * $item['quantity'];
                    $totalCents += $subtotalCents;

                    $lockedProducts[] = [
                        'product' => $product,
                        'quantity' => $item['quantity'],
                        'unit_price' => number_format($unitPriceCents / 100, 2, '.', ''),
                        'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
                    ];
                }

                $payAmountCents = (int) round(((float) $validated['pay_amount']) * 100);
                $isQris = $validated['payment_method'] === 'qris_gopay';
                $isDebt = $validated['payment_method'] === 'debt';

                if ($isQris && $payAmountCents !== $totalCents) {
                    throw ValidationException::withMessages([
                        'pay_amount' => 'Nominal pembayaran QRIS harus sama persis dengan total belanja.',
                    ]);
                }

                if ($isDebt && $payAmountCents >= $totalCents) {
                    throw ValidationException::withMessages([
                        'pay_amount' => 'Nominal pembayaran awal kasbon harus lebih kecil dari total belanja.',
                    ]);
                }

                if (!$isQris && !$isDebt && $payAmountCents < $totalCents) {
                    throw ValidationException::withMessages([
                        'pay_amount' => 'Nominal pembayaran tunai kurang dari total belanja.',
                    ]);
                }

                $customer = null;
                if (!empty($validated['customer_id'])) {
                    $customer = Customer::query()
                        ->whereKey($validated['customer_id'])
                        ->lockForUpdate()
                        ->first();

                    if (!$customer) {
                        throw ValidationException::withMessages([
                            'customer_id' => 'Data pelanggan tidak ditemukan.',
                        ]);
                    }
                }

                $totalPrice = number_format($totalCents / 100, 2, '.', '');
                $changeAmount = ($isQris || $isDebt)
                    ? '0.00'
                    : number_format(($payAmountCents - $totalCents) / 100, 2, '.', '');
                $invoiceNumber = 'INV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));

                $transaction = Transaction::create([
                    'user_id' => $request->user()->id,
                    'customer_id' => $validated['customer_id'] ?? null,
                    'invoice_number' => $invoiceNumber,
                    'total_price' => $totalPrice,
                    'pay_amount' => number_format(($isQris ? $totalCents : $payAmountCents) / 100, 2, '.', ''),
                    'payment_method' => $validated['payment_method'],
                    'change_amount' => $changeAmount,
                    'discount_amount' => 0,
                    'payment_status' => $isDebt ? 'unpaid' : 'paid',
                    'due_date' => null,
                    'qris_verified_by' => $isQris ? $request->user()->id : null,
                    'qris_verified_at' => $isQris ? now() : null,
                    'payment_reference' => $isQris ? ($validated['payment_reference'] ?? null) : null,
                    'idempotency_key' => $validated['idempotency_key'],
                    'idempotency_hash' => $payloadHash,
                ]);

                // Tambahkan piutang ke saldo total_debt pelanggan jika metode pembayaran adalah kasbon.
                if ($isDebt && $customer) {
                    $debtCents = $totalCents - $payAmountCents;
                    $currentDebtCents = (int) round(((float) $customer->total_debt) * 100);
                    $customer->total_debt = number_format(($currentDebtCents + $debtCents) / 100, 2, '.', '');
                    $customer->save();
                }

                foreach ($lockedProducts as $line) {
                    /** @var Product $product */
                    $product = $line['product'];
                    $product->stock -= $line['quantity'];
                    $product->save();

                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $product->id,
                        'quantity' => $line['quantity'],
                        'price' => $line['unit_price'],
                        'subtotal' => $line['subtotal'],
                    ]);

                    // Setiap penjualan harus tercatat di ledger persediaan.
                    StockMovement::create([
                        'product_id' => $product->id,
                        'user_id' => $request->user()->id,
                        'type' => 'out',
                        'quantity' => $line['quantity'],
                        'description' => 'Penjualan '.$invoiceNumber,
                    ]);
                }

                return $transaction->load(['details.product', 'customer', 'user', 'qrisVerifier:id,name']);
            }, 3);

            if (!$request->user()->isOwner()) {
                $transaction->details->each(function (TransactionDetail $detail): void {
                    $detail->product?->makeHidden('buy_price');
                });
            }

            return response()->json([
                'status' => 'success',
                'message' => match ($validated['payment_method']) {
                    'qris_gopay' => 'Transaksi QRIS GoPay berhasil dicatat setelah verifikasi manual Kasir.',
                    'debt' => 'Transaksi kasbon berhasil dicatat ke piutang pelanggan.',
                    default => 'Transaksi tunai berhasil.',
                },
                'data' => $transaction,
                'meta' => ['idempotent_replay' => false],
            ], 201);
        } catch (ValidationException $exception) {
            $existing = Transaction::with(['details.product', 'customer', 'user', 'qrisVerifier:id,name'])
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if ($existing) {
                return $this->respondForIdempotencyReplay($existing, $payloadHash, $request->user()->id, !$request->user()->isOwner());
            }

            throw $exception;
        } catch (QueryException $exception) {
            $existing = Transaction::with(['details.product', 'customer', 'user', 'qrisVerifier:id,name'])
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if ($existing) {
                return $this->respondForIdempotencyReplay($existing, $payloadHash, $request->user()->id, !$request->user()->isOwner());
            }

            throw $exception;
        }
    }

    public function show(Request $request, $id)
    {
        $transaction = Transaction::with(['user', 'customer', 'details.product', 'qrisVerifier:id,name'])->find($id);

        if (!$transaction) {
            return response()->json(['status' => 'error', 'message' => 'Transaksi tidak ditemukan.'], 404);
        }

        if (!$request->user()->isOwner() && $transaction->user_id !== $request->user()->id) {
            return response()->json(['status' => 'error', 'message' => 'Transaksi tidak ditemukan.'], 404);
        }

        if (!$request->user()->isOwner()) {
            $transaction->details->each(function (TransactionDetail $detail): void {
                $detail->product?->makeHidden('buy_price');
            });
        }

        return response()->json(['status' => 'success', 'data' => $transaction]);
    }

    private function respondForIdempotencyReplay(Transaction $transaction, string $payloadHash, int $userId, bool $hideCostPrices)
    {
        if ($transaction->user_id !== $userId || !hash_equals((string) $transaction->idempotency_hash, $payloadHash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Idempotency key sudah digunakan untuk transaksi atau data yang berbeda.',
            ], 409);
        }

        if ($hideCostPrices) {
            $transaction->details->each(function (TransactionDetail $detail): void {
                $detail->product?->makeHidden('buy_price');
            });
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Transaksi ini sudah pernah diproses; data transaksi dikembalikan tanpa mengurangi stok lagi.',
            'data' => $transaction,
            'meta' => ['idempotent_replay' => true],
        ]);
    }
}