<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeApiErrorResponseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->is('api/*') || $response->getStatusCode() < 400 || !$response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        if (is_array($payload) && isset($payload['error']) && is_array($payload['error'])) {
            return $response;
        }

        $status = $response->getStatusCode();
        $details = null;

        if (is_array($payload) && isset($payload['errors']) && is_array($payload['errors'])) {
            $details = $payload['errors'];
        } elseif (is_array($payload) && isset($payload['details']) && is_array($payload['details'])) {
            $details = $payload['details'];
        }

        if (is_array($payload) && isset($payload['retry_after'])) {
            $details = array_merge(is_array($details) ? $details : [], ['retry_after' => $payload['retry_after']]);
        }

        if (is_array($payload) && isset($payload['error']) && is_string($payload['error'])) {
            $details = array_merge(is_array($details) ? $details : [], ['raw_error' => $payload['error']]);
        }

        $message = is_array($payload) && isset($payload['message']) && is_string($payload['message'])
            ? $payload['message']
            : (Response::$statusTexts[$status] ?? 'HTTP error');

        $code = $this->statusCode($status);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'trace_id' => $request->header('X-Request-Id') ?: (string) str()->uuid(),
            ],
        ], $status);
    }

    private function statusCode(int $status): string
    {
        if ($status === 422) {
            return 'validation_error';
        }

        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            429 => 'too_many_requests',
            500 => 'server_error',
            default => 'http_error',
        };
    }
}
