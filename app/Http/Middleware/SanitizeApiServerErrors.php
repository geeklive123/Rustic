<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeApiServerErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response->getStatusCode() >= 500) {
            return response()->json(['error' => ['message' => 'Error interno del servidor.']], $response->getStatusCode());
        }

        return $response;
    }
}
