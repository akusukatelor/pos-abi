<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class CustomerWebAuthController extends Controller
{
    public function register(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:customer_accounts,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);

        // Jangan auto-link pelanggan kasir hanya berdasarkan nomor telepon/email.
        // Identitas offline memerlukan verifikasi terpisah agar histori kasbon tidak bocor.
        $account = DB::transaction(function () use ($data): CustomerAccount {
            $customer = Customer::create([
                'name' => trim($data['name']),
                'phone' => $data['phone'] ?? null,
                'total_debt' => '0.00',
            ]);

            return CustomerAccount::create([
                'customer_id' => $customer->id,
                'email' => strtolower(trim($data['email'])),
                'password' => $data['password'], // hashed cast pada model
            ]);
        });

        $token = $account->createToken('customer-web', ['customer'])->plainTextToken;

        return response()->json([
            'status' => 'success',
            'data' => ['account' => $this->accountInfo($account), 'access_token' => $token],
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $account = CustomerAccount::where('email', strtolower(trim($data['email'])))->first();
        if (!$account || !$account->is_active || !Hash::check($data['password'], $account->password)) {
            return response()->json(['status' => 'error', 'message' => 'Email atau password salah.'], 401);
        }

        $token = $account->createToken('customer-web', ['customer'])->plainTextToken;

        return response()->json([
            'status' => 'success',
            'data' => ['account' => $this->accountInfo($account), 'access_token' => $token],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['status' => 'success', 'data' => $this->accountInfo($request->user())]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        return response()->json(['status' => 'success', 'message' => 'Anda telah keluar.']);
    }

    private function accountInfo(CustomerAccount $account): array
    {
        $account->loadMissing('customer:id,name,phone');
        return [
            'id' => $account->id,
            'email' => $account->email,
            'name' => $account->customer?->name,
            'phone' => $account->customer?->phone,
        ];
    }
}
