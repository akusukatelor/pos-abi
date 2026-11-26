<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\LoyaltyPointTransaction;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyPointService
{
    public const RUPIAH_PER_POINT = 10000;
    public const RUPIAH_VALUE_PER_POINT = 1000;

    /**
     * Berikan poin otomatis untuk transaksi yang sudah berstatus LUNAS (paid)
     * bagi setiap pelanggan yang terdaftar di master customers.
     */
    public function awardForPaidTransaction(
        Transaction $transaction,
        ?int $actorUserId = null,
        ?int $onlineOrderId = null
    ): ?LoyaltyPointTransaction {
        if ($transaction->payment_status !== 'paid' || !$transaction->customer_id) {
            return null;
        }

        $points = (int) floor(((float) $transaction->total_price) / self::RUPIAH_PER_POINT);
        if ($points <= 0) {
            return null;
        }

        $accountId = CustomerAccount::query()
            ->where('customer_id', $transaction->customer_id)
            ->value('id');

        $referenceKey = 'earn:tx:'.$transaction->id;

        return LoyaltyPointTransaction::query()->firstOrCreate(
            ['reference_key' => $referenceKey],
            [
                'customer_id' => $transaction->customer_id,
                'customer_account_id' => $accountId,
                'online_order_id' => $onlineOrderId,
                'transaction_id' => $transaction->id,
                'created_by' => $actorUserId,
                'points_delta' => $points,
                'description' => "Perolehan poin belanja ({$transaction->invoice_number})",
            ]
        );
    }

    /**
     * Tukar poin pelanggan oleh Owner (berlaku untuk pelanggan offline maupun online).
     *
     * @return array{entry: LoyaltyPointTransaction, replayed: bool, balance: int}
     */
    public function redeemPoints(
        Customer $customer,
        int $points,
        string $rewardDescription,
        int $actorUserId,
        string $idempotencyKey
    ): array {
        $referenceKey = 'redeem:'.$idempotencyKey;

        return DB::transaction(function () use ($customer, $points, $rewardDescription, $actorUserId, $referenceKey): array {
            $lockedCustomer = Customer::query()
                ->whereKey($customer->id)
                ->lockForUpdate()
                ->firstOrFail();

            $accountId = CustomerAccount::query()
                ->where('customer_id', $lockedCustomer->id)
                ->value('id');

            $existing = LoyaltyPointTransaction::query()
                ->where('reference_key', $referenceKey)
                ->first();

            if ($existing) {
                $balance = (int) $lockedCustomer->loyaltyEntries()->sum('points_delta');
                return [
                    'entry' => $existing,
                    'replayed' => true,
                    'balance' => $balance,
                ];
            }

            $currentBalance = (int) $lockedCustomer->loyaltyEntries()->sum('points_delta');

            if ($points <= 0) {
                throw ValidationException::withMessages([
                    'points' => 'Jumlah poin yang ditukar minimal 1 poin.',
                ]);
            }

            if ($points > $currentBalance) {
                throw ValidationException::withMessages([
                    'points' => "Saldo poin tidak mencukupi. Saldo tersedia: {$currentBalance} poin.",
                ]);
            }

            $entry = LoyaltyPointTransaction::create([
                'customer_id' => $lockedCustomer->id,
                'customer_account_id' => $accountId,
                'online_order_id' => null,
                'transaction_id' => null,
                'created_by' => $actorUserId,
                'points_delta' => -$points,
                'description' => 'Penukaran poin: '.trim($rewardDescription),
                'reference_key' => $referenceKey,
            ]);

            return [
                'entry' => $entry,
                'replayed' => false,
                'balance' => $currentBalance - $points,
            ];
        }, 3);
    }
}