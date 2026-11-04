<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun tidak aktif. Silakan hubungi Owner.',
            ], 403);
        }

        return $next($request);
    }
}
