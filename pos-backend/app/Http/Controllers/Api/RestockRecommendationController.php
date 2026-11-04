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
                'calculation' => [
                    'reorder_point' => '(average_daily_sales * lead_time_days) + safety_stock',
                    'target_stock' => '(average_daily_sales * (lead_time_days + review_interval_days)) + safety_stock',
                    'suggested_order_quantity' => 'max(0, ceil(target_stock - current_stock)) when current_stock <= reorder_point',
                ],
                'sales_basis' => 'Only transactions with payment_status=paid are included.',
                'unit_basis' => 'The current product unit is used for both stock and sold quantity; unit conversion is not yet implemented.',
                'decision_support_only' => true,
            ],
            'data' => $paginator,
        ]);
    }
}
