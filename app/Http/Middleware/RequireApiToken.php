<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequireApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $request->bearerToken() || ! $user || ! $user->currentAccessToken() instanceof PersonalAccessToken || ! $user->is_active || $user->role === null) {
            abort(401, 'No autenticado.');
        }

        return $next($request);
    }
}
