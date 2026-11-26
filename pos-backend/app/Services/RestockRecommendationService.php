<?php

namespace App\Services;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RestockRecommendationService
{
    private const DEFAULT_SALES_PERIOD_DAYS = 30;
    private const DEFAULT_LEAD_TIME_DAYS = 2;
    private const DEFAULT_SAFETY_STOCK = 2;
    private const DEFAULT_REVIEW_INTERVAL_DAYS = 7;

    // Konstanta Holt's Damped Double Exponential Smoothing
    private const HOLT_ALPHA = 0.35; // Bobot data penjualan terbaru (level)
    private const HOLT_BETA = 0.15;  // Bobot kepekaan arah perubahan (trend)
    private const HOLT_PHI = 0.85;   // Faktor redaman tren agar prediksi multi-hari tetap realistis
    private const MIN_SERIES_DAYS_FOR_HOLT = 7;

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

        $salesSeriesByProduct = $this->aggregateDailySalesSeries($products);

        $recommendations = $products
            ->map(fn (Product $product): array => $this->buildRecommendation(
                $product,
                $salesSeriesByProduct[$product->id] ?? [
                    'daily_quantities' => array_fill(
                        0,
                        (int) ($product->restockSetting?->sales_period_days ?? self::DEFAULT_SALES_PERIOD_DAYS),
                        0,
                    ),
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
                'trending_up_count' => $recommendations->where('recommendation.trend_direction', 'up')->count(),
            ],
            'data' => $recommendations,
        ];
    }

    /**
     * Membentuk deret waktu harian (time-series) per produk dengan mengisi nilai 0 pada hari tanpa transaksi.
     *
     * @return array<int, array{daily_quantities: array<int, int>, units_sold: int, transaction_count: int}>
     */
    private function aggregateDailySalesSeries(Collection $products): array
    {
        $aggregates = [];

        foreach ($products->groupBy(
            fn (Product $product): int => (int) ($product->restockSetting?->sales_period_days ?? self::DEFAULT_SALES_PERIOD_DAYS),
        ) as $periodDays => $periodProducts) {
            $days = max(1, (int) $periodDays);
            $today = CarbonImmutable::today();
            $start = $today->subDays($days - 1)->startOfDay();
            $endExclusive = $today->addDay()->startOfDay();

            $rows = DB::table('transaction_details')
                ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
                ->where(function ($builder): void {
                    $builder->where('transactions.payment_method', 'debt')
                        ->orWhere('transactions.payment_status', 'paid');
                })
                ->where('transactions.created_at', '>=', $start->toDateTimeString())
                ->where('transactions.created_at', '<', $endExclusive->toDateTimeString())
                ->whereIn('transaction_details.product_id', $periodProducts->pluck('id'))
                ->select('transaction_details.product_id')
                ->selectRaw('DATE(transactions.created_at) as sale_date')
                ->selectRaw('COALESCE(SUM(transaction_details.quantity), 0) as units_sold')
                ->selectRaw('COUNT(DISTINCT transactions.id) as transaction_count')
                ->groupBy('transaction_details.product_id', DB::raw('DATE(transactions.created_at)'))
                ->get();

            $groupedRows = $rows->groupBy(fn ($row): int => (int) $row->product_id);

            foreach ($periodProducts as $product) {
                $productRows = ($groupedRows->get((int) $product->id) ?? collect())
                    ->keyBy(fn ($row): string => (string) $row->sale_date);

                $dailyQuantities = [];
                $totalUnits = 0;
                $totalTransactions = 0;

                for ($offset = 0; $offset < $days; $offset++) {
                    $dateKey = $start->addDays($offset)->toDateString();
                    $dayRow = $productRows->get($dateKey);

                    $qty = $dayRow ? (int) $dayRow->units_sold : 0;
                    $txCount = $dayRow ? (int) $dayRow->transaction_count : 0;

                    $dailyQuantities[] = $qty;
                    $totalUnits += $qty;
                    $totalTransactions += $txCount;
                }

                $aggregates[(int) $product->id] = [
                    'daily_quantities' => $dailyQuantities,
                    'units_sold' => $totalUnits,
                    'transaction_count' => $totalTransactions,
                ];
            }
        }

        return $aggregates;
    }

    /**
     * @param array{daily_quantities: array<int, int>, units_sold: int, transaction_count: int} $sales
     * @return array<string, mixed>
     */
    private function buildRecommendation(Product $product, array $sales): array
    {
        $setting = $product->restockSetting;
        $unit = $product->unit;
        $isDefault = $setting === null;

        $salesPeriodDays = (int) ($setting?->sales_period_days ?? self::DEFAULT_SALES_PERIOD_DAYS);
        $leadTimeDays = $isDefault ? self::DEFAULT_LEAD_TIME_DAYS : $setting->lead_time_days;
        $safetyStock = $isDefault ? self::DEFAULT_SAFETY_STOCK : $setting->safety_stock;
        $reviewIntervalDays = $isDefault ? self::DEFAULT_REVIEW_INTERVAL_DAYS : $setting->review_interval_days;
        $manualMinimumStock = $setting?->manual_minimum_stock;

        $today = CarbonImmutable::today();
        $periodStart = $today->subDays($salesPeriodDays - 1)->toDateString();

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
            'settings' => [
                'is_default' => $isDefault,
                'sales_period_days' => $salesPeriodDays,
                'lead_time_days' => $leadTimeDays,
                'safety_stock' => $safetyStock,
                'review_interval_days' => $reviewIntervalDays,
                'manual_minimum_stock' => $manualMinimumStock,
                'notes' => $setting?->notes,
            ],
            'sales_basis' => [
                'period_start' => $periodStart,
                'period_end' => $today->toDateString(),
                'period_days' => $salesPeriodDays,
                'units_sold' => $sales['units_sold'],
                'transaction_count' => $sales['transaction_count'],
                'included_status' => 'paid_or_debt',
            ],
        ];

        if (!$unit) {
            return $this->withInsufficientData(
                $base,
                'missing_product_unit',
                'Satuan produk belum ditetapkan sehingga jumlah stok tidak dapat ditampilkan dengan satuan yang jelas.',
            );
        }

        $formulaValues = [$leadTimeDays, $safetyStock, $reviewIntervalDays];
        $hasAnyFormulaValue = collect($formulaValues)->contains(fn ($value): bool => $value !== null);
        $hasCompleteFormula = collect($formulaValues)->every(fn ($value): bool => $value !== null);

        if ($hasAnyFormulaValue && !$hasCompleteFormula) {
            return $this->withInsufficientData(
                $base,
                'incomplete_restock_parameters',
                'Parameter lead time, safety stock, dan interval peninjauan harus diisi lengkap.',
            );
        }

        // Jalur 1: Peramalan Time-Series Holt's Double Exponential Smoothing + MAD
        if ($hasCompleteFormula && ($sales['units_sold'] > 0 || (int) $safetyStock > 0)) {
            $forecast = $this->forecastDemand($sales['daily_quantities'], $salesPeriodDays);
            $baseSafetyStock = (int) $safetyStock;

            // Buffer stok cadangan otomatis berdasarkan fluktuasi harian (MAD) selama masa tunggu supplier
            $volatilityBuffer = ($sales['units_sold'] > 0 && (int) $leadTimeDays > 0)
                ? (int) ceil(0.8 * $forecast['mad'] * sqrt((int) $leadTimeDays))
                : 0;
            $effectiveSafetyStock = $baseSafetyStock + $volatilityBuffer;

            $leadTimeDemand = $this->sumForecastHorizon(
                $forecast['level'],
                $forecast['trend'],
                (int) $leadTimeDays,
                $forecast['is_holt'],
            );
            $cycleDemand = $this->sumForecastHorizon(
                $forecast['level'],
                $forecast['trend'],
                (int) $leadTimeDays + (int) $reviewIntervalDays,
                $forecast['is_holt'],
            );

            $reorderPoint = $leadTimeDemand + $effectiveSafetyStock;
            $targetStock = $cycleDemand + $effectiveSafetyStock;
            $shouldRestock = (int) $product->stock <= $reorderPoint;
            $suggestedQuantity = $shouldRestock
                ? max(0, (int) ceil($targetStock - (int) $product->stock))
                : 0;

            return [
                ...$base,
                'recommendation' => [
                    'status' => $shouldRestock ? 'needs_restock' : 'sufficient',
                    'method' => $forecast['is_holt'] ? 'holt_double_exponential_smoothing' : 'sales_velocity',
                    'average_daily_sales' => number_format($forecast['next_day_forecast'], 4, '.', ''),
                    'forecast_daily_demand' => number_format($forecast['next_day_forecast'], 2, '.', ''),
                    'trend_direction' => $forecast['trend_direction'],
                    'trend_slope' => number_format($forecast['trend'], 4, '.', ''),
                    'mad' => number_format($forecast['mad'], 2, '.', ''),
                    'base_safety_stock' => $baseSafetyStock,
                    'safety_stock_buffer' => $volatilityBuffer,
                    'effective_safety_stock' => $effectiveSafetyStock,
                    'reorder_point' => number_format($reorderPoint, 2, '.', ''),
                    'target_stock' => number_format($targetStock, 2, '.', ''),
                    'suggested_order_quantity' => $suggestedQuantity,
                    'reason' => $this->buildHoltReason($shouldRestock, $sales['units_sold'], $forecast['trend_direction'], $volatilityBuffer, $isDefault),
                ],
            ];
        }

        // Jalur 2: Ambang minimum manual dari Owner
        if ($manualMinimumStock !== null) {
            $minimumStock = (int) $manualMinimumStock;
            $suggestedQuantity = max(0, $minimumStock - (int) $product->stock);
            $shouldRestock = $suggestedQuantity > 0;

            return [
                ...$base,
                'recommendation' => [
                    'status' => $shouldRestock ? 'needs_restock' : 'sufficient',
                    'method' => 'manual_minimum_stock',
                    'average_daily_sales' => null,
                    'forecast_daily_demand' => null,
                    'trend_direction' => 'stable',
                    'trend_slope' => '0.0000',
                    'mad' => null,
                    'base_safety_stock' => $minimumStock,
                    'safety_stock_buffer' => 0,
                    'effective_safety_stock' => $minimumStock,
                    'reorder_point' => number_format((float) $minimumStock, 2, '.', ''),
                    'target_stock' => number_format((float) $minimumStock, 2, '.', ''),
                    'suggested_order_quantity' => $suggestedQuantity,
                    'reason' => $sales['units_sold'] > 0
                        ? 'Rekomendasi menggunakan ambang minimum stok yang ditetapkan Owner.'
                        : 'Belum ada penjualan tercatat dalam periode analisis; rekomendasi menggunakan ambang minimum stok manual.',
                ],
            ];
        }

        if (!$hasCompleteFormula) {
            return $this->withInsufficientData(
                $base,
                'incomplete_restock_parameters',
                'Lead time, safety stock, dan interval peninjauan belum diatur lengkap.',
            );
        }

        return $this->withInsufficientData(
            $base,
            'no_sales_history',
            'Belum ada penjualan tercatat dalam periode analisis dan belum ada ambang minimum stok manual dari Owner.',
        );
    }

    /**
     * Menghitung Level (L_t), Trend (T_t), prediksi hari berikutnya, dan Mean Absolute Deviation (MAD).
     *
     * @param array<int, int> $dailyQuantities
     * @return array{
     *   is_holt: bool,
     *   level: float,
     *   trend: float,
     *   next_day_forecast: float,
     *   mad: float,
     *   trend_direction: string
     * }
     */
    private function forecastDemand(array $dailyQuantities, int $periodDays): array
    {
        $n = count($dailyQuantities);
        $totalUnits = array_sum($dailyQuantities);

        if ($totalUnits === 0) {
            return [
                'is_holt' => $n >= self::MIN_SERIES_DAYS_FOR_HOLT,
                'level' => 0.0,
                'trend' => 0.0,
                'next_day_forecast' => 0.0,
                'mad' => 0.0,
                'trend_direction' => 'stable',
            ];
        }

        // Jika periode analisis terlalu pendek (< 7 hari), gunakan rata-rata sederhana
        if ($n < self::MIN_SERIES_DAYS_FOR_HOLT) {
            $avg = $totalUnits / max(1, $periodDays);

            return [
                'is_holt' => false,
                'level' => $avg,
                'trend' => 0.0,
                'next_day_forecast' => $avg,
                'mad' => 0.0,
                'trend_direction' => 'stable',
            ];
        }

        // Inisialisasi level dan kemiringan tren awal dari 3 hari pertama
        $initWindow = min(3, $n - 1);
        $level = (float) $dailyQuantities[0];
        $trend = (($dailyQuantities[$initWindow] - $dailyQuantities[0]) / max(1, $initWindow)) * 0.5;

        $absoluteErrors = [];

        for ($t = 1; $t < $n; $t++) {
            $actual = (float) $dailyQuantities[$t];
            $oneStepForecast = max(0.0, $level + (self::HOLT_PHI * $trend));
            $absoluteErrors[] = abs($actual - $oneStepForecast);

            $prevLevel = $level;
            $prevTrend = $trend;

            $level = (self::HOLT_ALPHA * $actual) + ((1 - self::HOLT_ALPHA) * ($prevLevel + (self::HOLT_PHI * $prevTrend)));
            $trend = (self::HOLT_BETA * ($level - $prevLevel)) + ((1 - self::HOLT_BETA) * self::HOLT_PHI * $prevTrend);
        }

        $mad = count($absoluteErrors) > 0
            ? array_sum($absoluteErrors) / count($absoluteErrors)
            : 0.0;

        $dampedTrend = self::HOLT_PHI * $trend;
        $nextDayForecast = max(0.0, $level + $dampedTrend);

        $relativeRatio = $level > 0.01 ? ($dampedTrend / $level) : ($dampedTrend > 0 ? 1.0 : 0.0);
        $trendDirection = match (true) {
            $dampedTrend >= 0.05 && $relativeRatio >= 0.05 => 'up',
            $dampedTrend <= -0.05 && $relativeRatio <= -0.05 => 'down',
            default => 'stable',
        };

        return [
            'is_holt' => true,
            'level' => max(0.0, $level),
            'trend' => $trend,
            'next_day_forecast' => $nextDayForecast,
            'mad' => $mad,
            'trend_direction' => $trendDirection,
        ];
    }

    /**
     * Menjumlahkan ramalan permintaan untuk H hari ke depan menggunakan Damped Holt.
     */
    private function sumForecastHorizon(float $level, float $trend, int $horizonDays, bool $isHolt): float
    {
        if ($horizonDays <= 0) {
            return 0.0;
        }

        if (!$isHolt) {
            return max(0.0, $level) * $horizonDays;
        }

        $total = 0.0;
        $phiCumulative = 0.0;

        for ($h = 1; $h <= $horizonDays; $h++) {
            $phiCumulative += pow(self::HOLT_PHI, $h);
            $dayForecast = max(0.0, $level + ($trend * $phiCumulative));
            $total += $dayForecast;
        }

        return $total;
    }

    private function buildHoltReason(
        bool $shouldRestock,
        int $unitsSold,
        string $trendDirection,
        int $volatilityBuffer,
        bool $isDefault,
    ): string {
        if ($unitsSold === 0) {
            return 'Belum ada penjualan dalam periode analisis; perhitungan mengacu pada safety stock.';
        }

        $trendText = match ($trendDirection) {
            'up' => 'tren permintaan sedang naik',
            'down' => 'tren permintaan cenderung melambat',
            default => 'permintaan relatif stabil',
        };

        $bufferNote = $volatilityBuffer > 0
            ? " (+{$volatilityBuffer} buffer fluktuasi)"
            : '';

        $sourceNote = $isDefault ? ' [parameter default toko]' : '';

        return $shouldRestock
            ? "Stok di bawah reorder point; {$trendText}{$bufferNote}{$sourceNote}."
            : "Stok masih aman di atas reorder point; {$trendText}{$bufferNote}{$sourceNote}.";
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
                'forecast_daily_demand' => null,
                'trend_direction' => null,
                'trend_slope' => null,
                'mad' => null,
                'base_safety_stock' => null,
                'safety_stock_buffer' => null,
                'effective_safety_stock' => null,
                'reorder_point' => null,
                'target_stock' => null,
                'suggested_order_quantity' => null,
                'reason_code' => $code,
                'reason' => $message,
            ],
        ];
    }
}