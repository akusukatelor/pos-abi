<?php

namespace App\Http\Middleware;

use App\Models\CustomerAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();
        if (!$account instanceof CustomerAccount || !$account->is_active) {
            return response()->json(['status' => 'error', 'message' => 'Akses hanya untuk akun pelanggan aktif.'], 403);
        }

        return $next($request);
    }
}
