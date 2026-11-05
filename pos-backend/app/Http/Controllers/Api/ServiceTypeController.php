<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServiceType::query()->orderBy('name');

        if (!$request->user()->isOwner()) {
            $query->where('is_active', true);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:service_types,name'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $serviceType = ServiceType::create([
            ...$validated,
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Jenis layanan berhasil ditambahkan.',
            'data' => $serviceType,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $serviceType = ServiceType::query()->findOrFail($id);

        if (!$request->user()->isOwner() && !$serviceType->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Jenis layanan tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $serviceType,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $serviceType = ServiceType::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('service_types', 'name')->ignore($serviceType->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $serviceType->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Jenis layanan berhasil diperbarui.',
            'data' => $serviceType->refresh(),
        ]);
    }

    /**
     * Jenis layanan dinonaktifkan, bukan dihapus, agar histori transaksi tetap utuh.
     */
    public function destroy(int $id): JsonResponse
    {
        $serviceType = ServiceType::query()->findOrFail($id);
        $serviceType->update(['is_active' => false]);

        return response()->json([
            'status' => 'success',
            'message' => 'Jenis layanan dinonaktifkan. Histori transaksi tetap tersimpan.',
            'data' => $serviceType->refresh(),
        ]);
    }
}
