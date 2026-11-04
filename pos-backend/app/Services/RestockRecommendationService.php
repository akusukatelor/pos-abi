<?php

namespace App\Services;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RestockRecommendationService
{
    /**
     * @return array{
     *   summary: array<string, int>,
     *   data: Collection<int, array<string, mixed>>
     * }
     */
    public function recommendations(): array
    {
        $products = Product::query()
            ->with([
                'unit:id,name,short_name',
                'restockSetting',
            ])
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $salesByProduct = $this->aggregatePaidSales($products);

        $recommendations = $products
            ->map(fn (Product $product): array => $this->buildRecommendation(
                $product,
                $salesByProduct[$product->id] ?? [
                    'units_sold' => 0,
                    'transaction_count' => 0,
                ],
            ));

        return [
            'summary' => [
                'active_product_count' => $recommendations->count(),
                'needs_restock_count' => $recommendations->where('recommendation.status', 'needs_restock')->count(),
                'sufficient_count' => $recommendations->where('recommendation.status', 'sufficient')->count(),
                'insufficient_data_count' => $recommendations->where('recommendation.status', 'insufficient_data')->count(),
            ],
            'data' => $recommendations,
        ];
    }

    /**
     * Aggregate paid transaction quantities by product and configured sales window.
     *
     * @return array<int, array{units_sold: int, transaction_count: int}>
     */
    private function aggregatePaidSales(Collection $products): array
    {
        $configuredProducts = $products->filter(
            fn (Product $product): bool => $product->restockSetting !== null,
        );

        $aggregates = [];

        foreach ($configuredProducts->groupBy(
            fn (Product $product): int => (int) $product->restockSetting->sales_period_days,
        ) as $periodDays => $periodProducts) {
            $today = CarbonImmutable::today();
            $start = $today->subDays(((int) $periodDays) - 1)->startOfDay();
            $endExclusive = $today->addDay()->startOfDay();

            $rows = DB::table('transaction_details')
                ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
                ->where('transactions.payment_status', 'paid')
                ->where('transactions.created_at', '>=', $start->toDateTimeString())
                ->where('transactions.created_at', '<', $endExclusive->toDateTimeString())
                ->whereIn('transaction_details.product_id', $periodProducts->pluck('id'))
                ->select('transaction_details.product_id')
                ->selectRaw('COALESCE(SUM(transaction_details.quantity), 0) as units_sold')
                ->selectRaw('COUNT(DISTINCT transactions.id) as transaction_count')
                ->groupBy('transaction_details.product_id')
                ->get();

            foreach ($rows as $row) {
                $aggregates[(int) $row->product_id] = [
                    'units_sold' => (int) $row->units_sold,
                    'transaction_count' => (int) $row->transaction_count,
                ];
            }
        }

        return $aggregates;
    }

    /**
     * @param array{units_sold: int, transaction_count: int} $sales
     * @return array<string, mixed>
     */
    private function buildRecommendation(Product $product, array $sales): array
    {
        $setting = $product->restockSetting;
        $unit = $product->unit;

        $salesPeriodDays = $setting ? (int) $setting->sales_period_days : null;
        $today = CarbonImmutable::today();
        $periodStart = $salesPeriodDays
            ? $today->subDays($salesPeriodDays - 1)->toDateString()
            : null;

        $base = [
            'product' => [
                'id' => (int) $product->id,
                'sku_code' => $product->sku_code,
                'name' => $product->name,
            ],
            'current_stock' => (int) $product->stock,
            'unit' => $unit ? [
                'id' => (int) $unit->id,
                'name' => $unit->name,
                'short_name' => $unit->short_name,
            ] : null,
            'settings' => $setting ? [
                'sales_period_days' => $salesPeriodDays,
                'lead_time_days' => $setting->lead_time_days,
                'safety_stock' => $setting->safety_stock,
                'review_interval_days' => $setting->review_interval_days,
                'manual_minimum_stock' => $setting->manual_minimum_stock,
                'notes' => $setting->notes,
            ] : null,
            'sales_basis' => [
                'period_start' => $periodStart,
                'period_end' => $salesPeriodDays ? $today->toDateString() : null,
                'period_days' => $salesPeriodDays,
                'units_sold' => $sales['units_sold'],
                'transaction_count' => $sales['transaction_count'],
                'included_status' => 'paid',
            ],
        ];

        if (!$setting) {
            return $this->withInsufficientData($base, 'missing_restock_settings', 'Parameter rekomendasi restok produk ini belum diatur oleh Owner.');
        }

        if (!$unit) {
            return $this->withInsufficientData($base, 'missing_product_unit', 'Satuan produk belum ditetapkan sehingga jumlah stok tidak dapat ditampilkan dengan satuan yang jelas.');
        }

        $formulaValues = [
            $setting->lead_time_days,
            $setting->safety_stock,
            $setting->review_interval_days,
        ];
        $hasAnyFormulaValue = collect($formulaValues)->contains(fn ($value): bool => $value !== null);
        $hasCompleteFormula = collect($formulaValues)->every(fn ($value): bool => $value !== null);

        if ($hasAnyFormulaValue && !$hasCompleteFormula) {
            return $this->withInsufficientData($base, 'incomplete_restock_parameters', 'Parameter lead time, safety stock, dan interval peninjauan harus diisi lengkap.');
        }

        if ($sales['units_sold'] > 0 && $hasCompleteFormula) {
            $averageDailySales = $sales['units_sold'] / $salesPeriodDays;
            $reorderPoint = ($averageDailySales * (int) $setting->lead_time_days) + (int) $setting->safety_stock;
            $targetStock = ($averageDailySales * ((int) $setting->lead_time_days + (int) $setting->review_interval_days))
                + (int) $setting->safety_stock;
            $shouldRestock = (int) $product->stock <= $reorderPoint;
            $suggestedQuantity = $shouldRestock
                ? max(0, (int) ceil($targetStock - (int) $product->stock))
                : 0;

            return [
                ...$base,
                'recommendation' => [
                    'status' => $shouldRestock ? 'needs_restock' : 'sufficient',
                    'method' => 'sales_velocity',
                    'average_daily_sales' => number_format($averageDailySales, 4, '.', ''),
                    'reorder_point' => number_format($reorderPoint, 2, '.', ''),
                    'target_stock' => number_format($targetStock, 2, '.', ''),
                    'suggested_order_quantity' => $suggestedQuantity,
                    'reason' => $shouldRestock
                        ? 'Stok saat ini berada pada atau di bawah reorder point.'
                        : 'Stok saat ini masih berada di atas reorder point.',
                ],
            ];
        }

        if ($setting->manual_minimum_stock !== null) {
            $minimumStock = (int) $setting->manual_minimum_stock;
            $suggestedQuantity = max(0, $minimumStock - (int) $product->stock);
            $shouldRestock = $suggestedQuantity > 0;

            return [
                ...$base,
                'recommendation' => [
                    'status' => $shouldRestock ? 'needs_restock' : 'sufficient',
                    'method' => 'manual_minimum_stock',
                    'average_daily_sales' => null,
                    'reorder_point' => number_format((float) $minimumStock, 2, '.', ''),
                    'target_stock' => number_format((float) $minimumStock, 2, '.', ''),
                    'suggested_order_quantity' => $suggestedQuantity,
                    'reason' => $sales['units_sold'] > 0
                        ? 'Rekomendasi menggunakan ambang minimum stok yang ditetapkan Owner.'
                        : 'Belum ada penjualan berbayar dalam periode analisis; rekomendasi menggunakan ambang minimum stok yang ditetapkan Owner.',
                ],
            ];
        }

        if (!$hasCompleteFormula) {
            return $this->withInsufficientData($base, 'incomplete_restock_parameters', 'Lead time, safety stock, dan interval peninjauan belum diatur lengkap.');
        }

        return $this->withInsufficientData($base, 'no_sales_history', 'Belum ada penjualan berbayar dalam periode analisis dan belum ada ambang minimum stok manual dari Owner.');
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function withInsufficientData(array $base, string $code, string $message): array
    {
        return [
            ...$base,
            'recommendation' => [
                'status' => 'insufficient_data',
                'method' => null,
                'average_daily_sales' => null,
                'reorder_point' => null,
                'target_stock' => null,
                'suggested_order_quantity' => null,
                'reason_code' => $code,
                'reason' => $message,
            ],
        ];
    }
}
