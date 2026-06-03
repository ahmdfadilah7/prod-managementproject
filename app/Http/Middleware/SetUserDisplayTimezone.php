<?php

namespace App\Http\Middleware;

use App\Support\AppDateTime;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetUserDisplayTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $user->loadMissing('employee.branch');
            AppDateTime::setDisplayTimezone($user->displayTimezone());
        } else {
            AppDateTime::setDisplayTimezone(
                (string) config('managementpro.fallback_display_timezone', 'UTC')
            );
        }

        try {
            return $next($request);
        } finally {
            AppDateTime::setDisplayTimezone(null);
        }
    }
}
