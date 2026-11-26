<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RestockRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class RestockRecommendationController extends Controller
{
    public function index(Request $request, RestockRecommendationService $service): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:needs_restock,sufficient,insufficient_data'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $result = $service->recommendations();
        $recommendations = $result['data'];

        if (!empty($validated['status'])) {
            $recommendations = $recommendations->filter(
                fn (array $row): bool => $row['recommendation']['status'] === $validated['status'],
            )->values();
        }

        $perPage = (int) ($validated['per_page'] ?? 50);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $recommendations->forPage($page, $perPage)->values(),
            $recommendations->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );

        return response()->json([
            'status' => 'success',
            'summary' => $result['summary'],
            'meta' => [
                'algorithm' => 'Damped Holt Double Exponential Smoothing + MAD + Reorder Point',
                'calculation' => [
                    'forecast_model' => 'L_t = alpha * y_t + (1 - alpha) * (L_{t-1} + phi * T_{t-1}); T_t = beta * (L_t - L_{t-1}) + (1 - beta) * phi * T_{t-1}',
                    'effective_safety_stock' => 'base_safety_stock + ceil(0.8 * MAD * sqrt(lead_time_days))',
                    'reorder_point' => 'sum_forecast(lead_time_days) + effective_safety_stock',
                    'target_stock' => 'sum_forecast(lead_time_days + review_interval_days) + effective_safety_stock',
                    'suggested_order_quantity' => 'max(0, ceil(target_stock - current_stock)) when current_stock <= reorder_point',
                ],
                'sales_basis' => 'Completed sales (cash/QRIS paid and debt regardless of settlement status) aggregated as daily time-series; pending online orders are excluded.',
                'unit_basis' => 'The current product unit is used for both stock and sold quantity; unit conversion is not yet implemented.',
                'decision_support_only' => true,
            ],
            'data' => $paginator,
        ]);
    }
}