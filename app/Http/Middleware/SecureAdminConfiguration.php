<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureAdminConfiguration
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('admin', 'admin/*')) {
            $key = config('app.key');
            $key = is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
            $valid = is_string($key) && Encrypter::supported($key, config('app.cipher'));
            $secure = ! app()->environment('production') || ($request->isSecure() && config('session.secure') === true && config('session.http_only') === true && in_array(config('session.same_site'), ['lax', 'strict'], true) && ! in_array(config('session.driver'), ['array', 'cookie'], true));
            if (! $valid || ! $secure) {
                return response('Administraci?n no disponible: configure una clave y sesiones seguras.', 503);
            }
        }

        $response = $next($request);
        if ($request->is('admin', 'admin/*')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
