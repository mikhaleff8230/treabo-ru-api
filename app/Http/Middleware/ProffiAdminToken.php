<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ProffiAdminToken
{
    public function handle(Request $request, Closure $next)
    {
        $expected = config('services.proffi.admin_token') ?: env('PROFFI_ADMIN_TOKEN') ?: env('ADMIN_TOKEN') ?: 'admin';
        $actual = (string) $request->header('X-Admin-Token', '');

        if (!hash_equals((string) $expected, $actual)) {
            $user = $this->userFromBearerToken($request);

            if (!$this->isTreaboAdmin($user)) {
                return response()->json(['detail' => 'Unauthorized'], 401);
            }
        }

        return $next($request);
    }

    private function userFromBearerToken(Request $request): mixed
    {
        $token = $request->bearerToken();

        if (!$token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        return $accessToken?->tokenable;
    }

    private function isTreaboAdmin(mixed $user): bool
    {
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('super_admin')) {
            return true;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('super_admin')) {
            return true;
        }

        $permissions = method_exists($user, 'getPermissionNames')
            ? $user->getPermissionNames()->toArray()
            : ($user->user_permissions ?? []);

        return in_array('super_admin', (array) $permissions, true);
    }
}
