<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class RestrictReportNetwork
{
    public function handle(Request $request, Closure $next): Response
    {
        // REMOTE_ADDR is the actual peer, never a client-supplied forwarding header.
        $peer = $request->server('REMOTE_ADDR');
        $networks = config('reports.allowed_networks', []);
        if (! is_string($peer) || ! filter_var($peer, FILTER_VALIDATE_IP) || ! is_array($networks) || $networks === []) {
            abort(403);
        }
        foreach ($networks as $network) {
            if (! is_string($network) || ! preg_match('/^([^\/]+)(?:\/(\d{1,3}))?$/', $network, $parts)) {
                continue;
            }
            $ip = $parts[1];
            $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 6 : 0);
            $prefix = isset($parts[2]) ? (int) $parts[2] : ($version === 4 ? 32 : 128);
            if ($version && $prefix > 0 && $prefix <= ($version === 4 ? 32 : 128) && IpUtils::checkIp($peer, $network)) {
                $response = $next($request);
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

                return $response;
            }
        }
        abort(403);
    }
}
