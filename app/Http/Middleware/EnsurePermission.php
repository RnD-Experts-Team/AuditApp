<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        // ✅ super-admin bypass (request attribute source)
        $roles = $request->attributes->get('authz_roles', []);
        if (!is_array($roles)) $roles = [];

        if (in_array('super-admin', $roles, true)) {
            return $next($request);
        }

        // ✅ super-admin bypass (if local Spatie role exists)
        $user = Auth::user();
        if ($user && method_exists($user, 'hasRole')) {
            try {
                if ($user->hasRole('super-admin')) {
                    return $next($request);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $perms = $request->attributes->get('authz_permissions', []);
        if (!is_array($perms)) $perms = [];

        if (!in_array($permission, $perms, true)) {
            abort(403, 'Missing permission');
        }

        return $next($request);
    }
}
