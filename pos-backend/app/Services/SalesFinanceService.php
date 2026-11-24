<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Metrik penjualan berbasis tanggal TRANSAKSI, penerimaan kas berbasis tanggal PEMBAYARAN.
 * Piutang kasbon yang kemudian lunas tetap dihitung pada tanggal penjualan aslinya.
 */
class SalesFinanceService
{
    public function period(string $start, string $endExclusive): array
    {
        $sales = DB::table('transactions')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $endExclusive)
            ->where(function ($query): void {
                $query->where('payment_method', 'debt')
                    ->orWhere('payment_status', 'paid');
            });

        $debtSales = (clone $sales)->where('payment_method', 'debt');
        $paidSales = (clone $sales)->whereIn('payment_method', ['cash', 'qris_gopay']);

        // Cicilan terpisah menggunakan ledger P0, BUKAN transactions.pay_amount
        // (karena field itu ikut berubah ketika kasbon dicicil).
        $collections = DB::table('customer_debt_payments')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $endExclusive);

        // Kasbon dengan DP tunai: total pay_amount kini sudah termasuk cicilan berikutnya.
        // Kurangi seluruh alokasi ledger per invoice untuk menemukan DP saat checkout.
        $allocations = DB::table('customer_debt_payment_allocations')
            ->select('transaction_id')
            ->selectRaw('SUM(amount) as allocated')
            ->groupBy('transaction_id');
        $downPayments = DB::table('transactions')
            ->leftJoinSub($allocations, 'repayments', fn ($join) => $join->on('repayments.transaction_id', '=', 'transactions.id'))
            ->where('transactions.payment_method', 'debt')
            ->where('transactions.created_at', '>=', $start)
            ->where('transactions.created_at', '<', $endExclusive)
            ->selectRaw('COALESCE(SUM(CASE WHEN transactions.pay_amount > COALESCE(repayments.allocated, 0) THEN transactions.pay_amount - COALESCE(repayments.allocated, 0) ELSE 0 END), 0) as initial_paid')
            ->first()->initial_paid;

        $initialPaid = (float) $downPayments;
        $paidAtCheckout = (float) (clone $paidSales)->sum('total_price');
        $collectedDebt = (float) (clone $collections)->sum('amount');

        return [
            'transaction_count' => (clone $sales)->count(),
            'omzet_barang' => $this->money((clone $sales)->sum('total_price')),
            'paid_sale_count' => (clone $paidSales)->count(),
            'kasbon_transaction_count' => (clone $debtSales)->count(),
            'kasbon_sales' => $this->money((clone $debtSales)->sum('total_price')),
            'cash_or_qris_sales' => $this->money($paidAtCheckout),
            'kasbon_down_payments' => $this->money($initialPaid),
            'kasbon_collections' => $this->money($collectedDebt),
            'kasbon_collection_count' => (clone $collections)->count(),
            'cash_received' => $this->money($paidAtCheckout + $initialPaid + $collectedDebt),
            'cash_received_note' => 'Penerimaan periode ini = penjualan tunai/QRIS saat checkout + DP kasbon saat checkout + pelunasan kasbon pada tanggal dibayar. Tidak sama dengan omzet penjualan periode ini.',
        ];
    }

    public static function scopeSales($query): void
    {
        $query->where(function ($builder): void {
            $builder->where('payment_method', 'debt')
                ->orWhere('payment_status', 'paid');
        });
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
