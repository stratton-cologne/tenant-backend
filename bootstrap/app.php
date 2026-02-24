<?php

use App\Http\Middleware\AuthenticateApiTokenMiddleware;
use App\Http\Middleware\AuthenticateInternalSyncTokenMiddleware;
use App\Http\Middleware\CheckPermissionMiddleware;
use App\Http\Middleware\CheckTenantEntitlementMiddleware;
use App\Http\Middleware\ApplyTenantMailSettingsMiddleware;
use App\Http\Middleware\NormalizeApiErrorResponseMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.token' => AuthenticateApiTokenMiddleware::class,
            'internal.sync.token' => AuthenticateInternalSyncTokenMiddleware::class,
            'permission' => CheckPermissionMiddleware::class,
            'entitled' => CheckTenantEntitlementMiddleware::class,
        ]);

        $middleware->prependToGroup('api', ApplyTenantMailSettingsMiddleware::class);
        $middleware->appendToGroup('api', NormalizeApiErrorResponseMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            $status = 500;
            $code = 'server_error';
            $message = 'Unexpected server error';
            $details = null;

            if ($exception instanceof ValidationException) {
                $status = 422;
                $code = 'validation_error';
                $message = 'Request validation failed';
                $details = $exception->errors();
            } elseif ($exception instanceof AuthenticationException) {
                $status = 401;
                $code = 'unauthenticated';
                $message = 'Authentication required';
            } elseif ($exception instanceof AuthorizationException) {
                $status = 403;
                $code = 'forbidden';
                $message = 'Action not permitted';
            } elseif ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $message = $exception->getMessage() !== '' ? $exception->getMessage() : 'HTTP error';
                $code = match ($status) {
                    400 => 'bad_request',
                    401 => 'unauthenticated',
                    403 => 'forbidden',
                    404 => 'not_found',
                    405 => 'method_not_allowed',
                    409 => 'conflict',
                    422 => 'validation_error',
                    429 => 'too_many_requests',
                    default => 'http_error',
                };
            }

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'details' => $details,
                    'trace_id' => $request->header('X-Request-Id') ?: (string) str()->uuid(),
                ],
            ], $status);
        });
    })->create();
