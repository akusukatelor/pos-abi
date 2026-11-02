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
        // Tampilkan semua akun (admin bisa melihat semua kasir)
        $users = User::all();
        return response()->json(['status' => 'success', 'data' => $users]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'role' => 'required|in:admin,kasir'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password), // Password wajib di-hash
            'role' => $request->role
        ]);

        return response()->json(['status' => 'success', 'message' => 'Akun berhasil dibuat', 'data' => $user], 201);
    }

    public function show($id)
    {
        $user = User::find($id);
        if (!$user) return response()->json(['status' => 'error', 'message' => 'Akun tidak ditemukan'], 404);
        return response()->json(['status' => 'success', 'data' => $user]);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) return response()->json(['status' => 'error', 'message' => 'Akun tidak ditemukan'], 404);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $id,
            'role' => 'required|in:admin,kasir'
        ]);

        // Update data dasar
        $user->name = $request->name;
        $user->email = $request->email;
        $user->role = $request->role;

        // Jika password diisi, maka update password (jika kosong, abaikan)
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return response()->json(['status' => 'success', 'message' => 'Akun berhasil diupdate', 'data' => $user]);
    }

    public function destroy($id)
    {
        $user = User::find($id);
        if (!$user) return response()->json(['status' => 'error', 'message' => 'Akun tidak ditemukan'], 404);
        
        $user->delete();
        return response()->json(['status' => 'success', 'message' => 'Akun berhasil dihapus']);
    }
}