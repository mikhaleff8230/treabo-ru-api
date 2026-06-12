<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ProffiAdminToken
{
    public function handle(Request $request, Closure $next)
    {
        $expected = config('services.proffi.admin_token') ?: env('PROFFI_ADMIN_TOKEN') ?: env('ADMIN_TOKEN') ?: 'admin';
        $actual = (string) $request->header('X-Admin-Token', '');

        if (!hash_equals((string) $expected, $actual)) {
            return response()->json(['detail' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
