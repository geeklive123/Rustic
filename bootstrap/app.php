<?php

use App\Http\Middleware\RequireApiToken;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SanitizeApiServerErrors;
use App\Http\Middleware\SecureAdminConfiguration;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecureAdminConfiguration::class);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
        $middleware->alias(['api.token' => RequireApiToken::class, 'permission' => RequirePermission::class]);
        $middleware->api(append: [SanitizeApiServerErrors::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(function (Response $response): Response {
            if (! request()->is('api/*') || $response->getStatusCode() < 400) {
                return $response;
            }
            $original = json_decode((string) $response->getContent(), true) ?: [];
            $status = $response->getStatusCode();
            $error = ['message' => $status >= 500 ? 'Error interno del servidor.' : ($original['message'] ?? Response::$statusTexts[$status] ?? 'Error')];
            if ($status === 422 && isset($original['errors'])) {
                $error['details'] = $original['errors'];
            }

            return response()->json(['error' => $error], $status, $response->headers->all());
        });
    })->create();
