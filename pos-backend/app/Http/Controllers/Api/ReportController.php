<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\ServiceTransaction;
use App\Models\StockMovement;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        $sales = Transaction::query()
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $to]);

        $purchases = $this->applyDateRange(
            PurchaseReceipt::query()->where('status', 'confirmed'),
            'received_at',
            $from,
            $to,
        );

        $services = ServiceTransaction::query()
            ->where('status', 'completed')
            ->whereBetween('service_at', [$from, $to]);

        $verifiedServices = (clone $services)->whereNotNull('margin_verified_by');
        $unverifiedServices = (clone $services)->whereNull('margin_verified_by');

        return response()->json([
            'status' => 'success',
            'period' => $this->periodPayload($from, $to),
            'data' => [
                'sales' => [
                    'transaction_count' => (clone $sales)->count(),
                    'omzet_barang' => number_format((float) (clone $sales)->sum('total_price'), 2, '.', ''),
                    'gross_profit' => null,
                    'gross_profit_status' => 'not_calculated',
                    'gross_profit_note' => 'Metode HPP dan snapshot harga pokok pada waktu penjualan belum ditetapkan.',
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
                ],
            ],
        ]);
    }

    public function sales(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        $query = Transaction::query()
            ->with(['user:id,name', 'customer:id,name'])
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $to]);

        $paymentBreakdown = DB::table('transactions')
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $to])
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
            ]);

        return response()->json([
            'status' => 'success',
            'period' => $this->periodPayload($from, $to),
            'summary' => [
                'transaction_count' => (clone $query)->count(),
                'omzet_barang' => number_format((float) (clone $query)->sum('total_price'), 2, '.', ''),
                'gross_profit' => null,
                'gross_profit_status' => 'not_calculated',
                'gross_profit_note' => 'Metode HPP dan snapshot harga pokok pada waktu penjualan belum ditetapkan.',
            ],
            'payment_breakdown' => $paymentBreakdown,
            'data' => $query->latest()->paginate(25),
        ]);
    }

    public function purchases(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        $query = $this->applyDateRange(
            PurchaseReceipt::query()
                ->with(['supplier:id,name', 'creator:id,name', 'confirmer:id,name', 'items.product:id,name,sku_code'])
                ->where('status', 'confirmed'),
            'received_at',
            $from,
            $to,
        );

        $bySupplierQuery = DB::table('purchase_receipts')
            ->join('suppliers', 'purchase_receipts.supplier_id', '=', 'suppliers.id')
            ->where('purchase_receipts.status', 'confirmed');
        $bySupplier = $this->applyDateRange(
            $bySupplierQuery,
            'purchase_receipts.received_at',
            $from,
            $to,
        )
            ->select('suppliers.id', 'suppliers.name')
            ->selectRaw('COUNT(purchase_receipts.id) as receipt_count')
            ->selectRaw('SUM(purchase_receipts.total_amount) as total_pembelian')
            ->groupBy('suppliers.id', 'suppliers.name')
            ->orderBy('suppliers.name')
            ->get()
            ->map(fn ($row): array => [
                'supplier_id' => (int) $row->id,
                'supplier_name' => $row->name,
                'receipt_count' => (int) $row->receipt_count,
                'total_pembelian' => number_format((float) $row->total_pembelian, 2, '.', ''),
            ]);

        return response()->json([
            'status' => 'success',
            'period' => $this->periodPayload($from, $to),
            'summary' => [
                'receipt_count' => (clone $query)->count(),
                'total_pembelian' => number_format((float) (clone $query)->sum('total_amount'), 2, '.', ''),
            ],
            'by_supplier' => $bySupplier,
            'data' => $query->latest('received_at')->paginate(25),
        ]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'zero_stock_only' => ['nullable', 'boolean'],
        ]);

        $query = Product::query()
            ->with(['category:id,name', 'unit:id,name,short_name'])
            ->where('is_active', true)
            ->orderBy('name');

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('sku_code', 'like', "%{$search}%");
            });
        }

        if (($validated['zero_stock_only'] ?? false) === true || ($validated['zero_stock_only'] ?? false) === '1') {
            $query->where('stock', 0);
        }

        return response()->json([
            'status' => 'success',
            'summary' => [
                'active_product_count' => Product::query()->where('is_active', true)->count(),
                'zero_stock_product_count' => Product::query()->where('is_active', true)->where('stock', 0)->count(),
                'negative_stock_product_count' => Product::query()->where('is_active', true)->where('stock', '<', 0)->count(),
            ],
            'data' => $query->paginate(50),
        ]);
    }

    public function services(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        $query = ServiceTransaction::query()
            ->with(['serviceType:id,name', 'user:id,name', 'marginVerifier:id,name'])
            ->where('status', 'completed')
            ->whereBetween('service_at', [$from, $to]);

        $verified = (clone $query)->whereNotNull('margin_verified_by');
        $unverified = (clone $query)->whereNull('margin_verified_by');

        return response()->json([
            'status' => 'success',
            'period' => $this->periodPayload($from, $to),
            'summary' => [
                'completed_count' => (clone $query)->count(),
                'verified_margin_count' => (clone $verified)->count(),
                'verified_margin_total' => number_format((float) (clone $verified)->sum('margin_amount'), 2, '.', ''),
                'margin_not_verified_count' => (clone $unverified)->count(),
                'nominal_pokok_total' => number_format((float) (clone $query)->sum('nominal_amount'), 2, '.', ''),
                'nominal_pokok_note' => 'Nominal pokok layanan bukan pendapatan toko.',
            ],
            'data' => $query->latest('service_at')->paginate(25),
        ]);
    }

    /**
     * Apply an inclusive calendar-date range using an exclusive next-day bound.
     * This includes rows stored as DATE and SQLite test values stored as DATETIME,
     * without wrapping the indexed column in DATE().
     */
    private function applyDateRange($query, string $column, CarbonImmutable $from, CarbonImmutable $to)
    {
        return $query
            ->where($column, '>=', $from->toDateString())
            ->where($column, '<', $to->addDay()->toDateString());
    }

    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = CarbonImmutable::parse($validated['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($validated['to'] ?? now()->toDateString())->endOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'to' => 'Tanggal akhir tidak boleh lebih awal dari tanggal awal.',
            ]);
        }

        return [$from, $to];
    }

    private function periodPayload(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }
}
