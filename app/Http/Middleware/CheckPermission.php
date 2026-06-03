<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $user->is_active) {
            abort(403, 'Akun Anda tidak aktif.');
        }

        if (empty($permissions)) {
            return $next($request);
        }

        if ($user->hasAnyPermission($permissions)) {
            return $next($request);
        }

        abort(403, 'Anda tidak memiliki izin untuk aksi ini.');
    }
}
