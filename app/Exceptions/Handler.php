<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        // $this->reportable(function (Throwable $e) { });
    }

    private function apiMeta($request): array|object
    {
        $rid = $request->attributes->get('request_id') ?? $request->header('X-Request-Id');
        return $rid ? ['request_id' => (string) $rid] : (object)[];
    }

    private function apiFail($request, string $message, int $status, array|object $details = null, array $headers = [])
    {
        return response()->json([
            'data' => null,
            'meta' => $this->apiMeta($request),
            'errors' => [
                'message' => $message,
                'details' => $details ?? (object)[],
            ],
        ], $status, $headers);
    }

    public function render($request, Throwable $e)
    {
        // API wrapper: اشتغل فقط على /api/*
        if ($request->is('api/*') || $request->expectsJson()) {

            // Validation
            if ($e instanceof ValidationException) {
                // Backward compatible shape:
                // - Keep errors.message + errors.details (existing clients)
                // - Also flatten field errors at the same level under "errors" so
                //   Laravel's assertJsonValidationErrors() works with default responseKey="errors".
                $fieldErrors = $e->errors();

                return response()->json([
                    'data' => null,
                    'meta' => $this->apiMeta($request),
                    'errors' => array_merge(
                        [
                            'message' => 'Validation error',
                            'details' => $fieldErrors,
                        ],
                        $fieldErrors,
                    ),
                ], 422);
            }

            // Throttle / Rate limit
            if ($e instanceof ThrottleRequestsException) {
                $headers = method_exists($e, 'getHeaders') ? $e->getHeaders() : [];
                $details = (object)[];
                if (isset($headers['Retry-After'])) {
                    $details = ['retry_after' => (int) $headers['Retry-After']];
                }
                return $this->apiFail($request, 'Too many requests', 429, $details, $headers);
            }

            // Unauthenticated
            if ($e instanceof AuthenticationException) {
                return $this->apiFail($request, 'Unauthenticated', 401);
            }

            // Forbidden
            if ($e instanceof AuthorizationException) {
                return $this->apiFail($request, 'Forbidden', 403);
            }

            // JWT package (اختياري + آمن حتى لو ما كان موجود)
            if (class_exists(\Tymon\JWTAuth\Exceptions\TokenExpiredException::class) && $e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException) {
                return $this->apiFail($request, 'Token expired', 401);
            }
            if (class_exists(\Tymon\JWTAuth\Exceptions\TokenInvalidException::class) && $e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException) {
                return $this->apiFail($request, 'Token invalid', 401);
            }
            if (class_exists(\Tymon\JWTAuth\Exceptions\JWTException::class) && $e instanceof \Tymon\JWTAuth\Exceptions\JWTException) {
                return $this->apiFail($request, 'Unauthenticated', 401);
            }

            // ✅ 409 - Invalid state transition
            if ($e instanceof \App\Exceptions\InvalidStateTransitionException) {
                return $this->apiFail($request, $e->getMessage() ?: 'Invalid state transition', 409);
            }

            // Not found (Model)
            if ($e instanceof ModelNotFoundException) {
                return $this->apiFail($request, 'Not found', 404);
            }

            // Not found (Route)
            if ($e instanceof NotFoundHttpException) {
                return $this->apiFail($request, 'Not found', 404);
            }

            // Method not allowed
            if ($e instanceof MethodNotAllowedHttpException) {
                return $this->apiFail($request, 'Method not allowed', 405);
            }

            // HTTP exceptions (400/403/500...)
            if ($e instanceof HttpExceptionInterface) {
                $code = $e->getStatusCode();
                $msg = $e->getMessage() ?: 'HTTP error';
                $headers = method_exists($e, 'getHeaders') ? $e->getHeaders() : [];
                return $this->apiFail($request, $msg, $code, (object)[], $headers);
            }

            // 500
            $debug = (bool) config('app.debug');
            $details = $debug ? [
                'exception' => class_basename($e),
            ] : (object)[];

            return $this->apiFail(
                $request,
                $debug ? ($e->getMessage() ?: 'Server error') : 'Server error',
                500,
                $details
            );
        }

        return parent::render($request, $e);
    }
}
