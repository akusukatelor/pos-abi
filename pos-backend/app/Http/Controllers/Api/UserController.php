<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'is_active', 'created_at'])
            ->latest()
            ->paginate(25);

        return response()->json(['status' => 'success', 'data' => $users]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:admin,kasir'],
        ]);

        $user = User::create([
            ...$validated,
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Akun berhasil dibuat.',
            'data' => $user->only(['id', 'name', 'email', 'role', 'is_active']),
        ], 201);
    }

    public function show($id)
    {
        $user = User::query()->select(['id', 'name', 'email', 'role', 'is_active', 'created_at'])->find($id);

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Akun tidak ditemukan.'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $user]);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Akun tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', 'unique:users,email,'.$id],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'required', 'in:admin,kasir'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        if ($user->isOwner() && $user->id === $request->user()->id) {
            if (($validated['role'] ?? $user->role) !== 'admin' || ($validated['is_active'] ?? true) === false) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Owner tidak dapat menonaktifkan atau menurunkan role akunnya sendiri.',
                ], 422);
            }
        }

        if (array_key_exists('password', $validated)) {
            if (filled($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }
            unset($validated['password']);
        }

        $user->fill($validated)->save();

        if (!$user->is_active) {
            $user->tokens()->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Akun berhasil diperbarui.',
            'data' => $user->only(['id', 'name', 'email', 'role', 'is_active']),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Akun tidak ditemukan.'], 404);
        }

        if ($user->id === $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun yang sedang digunakan tidak dapat dinonaktifkan.',
            ], 422);
        }

        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Akun dinonaktifkan. Riwayat transaksi tetap dipertahankan.',
        ]);
    }
}
