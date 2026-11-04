<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\RestockRecommendationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OwnerDashboardController extends Controller
{
    public function show(Request $request, RestockRecommendationService $restockService): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = CarbonImmutable::parse(
            $validated['from'] ?? now()->startOfMonth()->toDateString(),
        )->startOfDay();

        $to = CarbonImmutable::parse(
            $validated['to'] ?? now()->toDateString(),
        )->startOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'to' => 'Tanggal akhir tidak boleh lebih awal dari tanggal awal.',
            ]);
        }

        $endExclusive = $to->addDay()->startOfDay();
        $fromDateTime = $from->toDateTimeString();
        $endDateTime = $endExclusive->toDateTimeString();

        $sales = DB::table('transactions')
            ->where('payment_status', 'paid')
            ->where('created_at', '>=', $fromDateTime)
            ->where('created_at', '<', $endDateTime);

        $paymentBreakdown = (clone $sales)
            ->select('payment_method')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('SUM(total_price) as omzet')
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get()
            ->map(fn ($row): array => [
                'payment_method' => $row->payment_method,
                'transaction_count' => (int) $row->transaction_count,
                'omzet' => number_format((float) $row->omzet, 2, '.', ''),
            ])
            ->values();

        $purchases = DB::table('purchase_receipts')
            ->where('status', 'confirmed')
            ->where('received_at', '>=', $from->toDateString())
            ->where('received_at', '<', $endExclusive->toDateString());

        $services = DB::table('service_transactions')
            ->where('status', 'completed')
            ->where('service_at', '>=', $fromDateTime)
            ->where('service_at', '<', $endDateTime);

        $verifiedServices = (clone $services)->whereNotNull('margin_verified_by');
        $unverifiedServices = (clone $services)->whereNull('margin_verified_by');

        $topProducts = DB::table('transaction_details')
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->where('transactions.payment_status', 'paid')
            ->where('transactions.created_at', '>=', $fromDateTime)
            ->where('transactions.created_at', '<', $endDateTime)
            ->select([
                'products.id',
                'products.name',
                'products.sku_code',
                'units.name as unit_name',
                'units.short_name as unit_short_name',
            ])
            ->selectRaw('SUM(transaction_details.quantity) as units_sold')
            ->selectRaw('SUM(transaction_details.subtotal) as omzet')
            ->groupBy(
                'products.id',
                'products.name',
                'products.sku_code',
                'units.name',
                'units.short_name',
            )
            ->orderByDesc('units_sold')
            ->orderByDesc('omzet')
            ->orderBy('products.name')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'product_id' => (int) $row->id,
                'name' => $row->name,
                'sku_code' => $row->sku_code,
                'unit' => $row->unit_short_name ?? $row->unit_name,
                'units_sold' => (int) $row->units_sold,
                'omzet' => number_format((float) $row->omzet, 2, '.', ''),
            ])
            ->values();

        $salesTrend = DB::table('transactions')
            ->where('payment_status', 'paid')
            ->where('created_at', '>=', $fromDateTime)
            ->where('created_at', '<', $endDateTime)
            ->selectRaw('DATE(created_at) as sale_date')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('SUM(total_price) as omzet')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('sale_date')
            ->get()
            ->map(fn ($row): array => [
                'date' => $row->sale_date,
                'transaction_count' => (int) $row->transaction_count,
                'omzet' => number_format((float) $row->omzet, 2, '.', ''),
            ])
            ->values();

        $restockSummary = $restockService->recommendations()['summary'];

        return response()->json([
            'status' => 'success',
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'data' => [
                'sales' => [
                    'transaction_count' => (clone $sales)->count(),
                    'omzet_barang' => number_format((float) (clone $sales)->sum('total_price'), 2, '.', ''),
                    'gross_profit' => null,
                    'gross_profit_status' => 'not_calculated',
                    'gross_profit_note' => 'Metode HPP dan snapshot harga pokok pada waktu penjualan belum ditetapkan.',
                    'payment_breakdown' => $paymentBreakdown,
                ],
                'purchases' => [
                    'receipt_count' => (clone $purchases)->count(),
                    'total_pembelian' => number_format((float) (clone $purchases)->sum('total_amount'), 2, '.', ''),
                ],
                'services' => [
                    'completed_count' => (clone $services)->count(),
                    'verified_margin_count' => (clone $verifiedServices)->count(),
                    'verified_margin_total' => number_format((float) (clone $verifiedServices)->sum('margin_amount'), 2, '.', ''),
                    'margin_not_verified_count' => (clone $unverifiedServices)->count(),
                    'nominal_pokok_total' => number_format((float) (clone $services)->sum('nominal_amount'), 2, '.', ''),
                    'nominal_pokok_note' => 'Nominal pokok layanan bukan pendapatan toko.',
                ],
                'inventory' => [
                    'active_product_count' => Product::query()->where('is_active', true)->count(),
                    'zero_stock_product_count' => Product::query()->where('is_active', true)->where('stock', 0)->count(),
                    'needs_restock_count' => $restockSummary['needs_restock_count'],
                    'insufficient_data_count' => $restockSummary['insufficient_data_count'],
                ],
                'top_products' => $topProducts,
                'sales_trend' => $salesTrend,
            ],
        ]);
    }
}
