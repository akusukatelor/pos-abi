<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductRestockSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RestockSettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Product::query()
            ->with(['unit:id,name,short_name', 'restockSetting'])
            ->where('is_active', true)
            ->orderBy('name');

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('sku_code', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate(50),
        ]);
    }

    public function update(Request $request, int $productId): JsonResponse
    {
        $validated = $request->validate([
            'sales_period_days' => ['required', 'integer', 'between:1,365'],
            'lead_time_days' => ['nullable', 'integer', 'between:0,365'],
            'safety_stock' => ['nullable', 'integer', 'between:0,1000000'],
            'review_interval_days' => ['nullable', 'integer', 'between:1,365'],
            'manual_minimum_stock' => ['nullable', 'integer', 'between:0,1000000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $formulaValues = [
            $validated['lead_time_days'] ?? null,
            $validated['safety_stock'] ?? null,
            $validated['review_interval_days'] ?? null,
        ];
        $hasAnyFormulaValue = collect($formulaValues)->contains(fn ($value): bool => $value !== null);
        $hasCompleteFormula = collect($formulaValues)->every(fn ($value): bool => $value !== null);
        $hasManualThreshold = ($validated['manual_minimum_stock'] ?? null) !== null;

        if (($hasAnyFormulaValue && !$hasCompleteFormula) || (!$hasCompleteFormula && !$hasManualThreshold)) {
            throw ValidationException::withMessages([
                'parameters' => 'Isi lead time, safety stock, dan interval peninjauan secara lengkap, atau tetapkan manual minimum stock.',
            ]);
        }

        $product = Product::query()
            ->where('is_active', true)
            ->findOrFail($productId);

        $setting = ProductRestockSetting::query()->firstOrNew([
            'product_id' => $product->id,
        ]);

        if (!$setting->exists) {
            $setting->created_by = $request->user()->id;
        }

        $setting->fill([
            'sales_period_days' => $validated['sales_period_days'],
            'lead_time_days' => $validated['lead_time_days'] ?? null,
            'safety_stock' => $validated['safety_stock'] ?? null,
            'review_interval_days' => $validated['review_interval_days'] ?? null,
            'manual_minimum_stock' => $validated['manual_minimum_stock'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'updated_by' => $request->user()->id,
        ]);
        $setting->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Parameter rekomendasi restok berhasil disimpan.',
            'data' => $product->load(['unit:id,name,short_name', 'restockSetting']),
        ]);
    }
}
