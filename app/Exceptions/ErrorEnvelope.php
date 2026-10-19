<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every API error in one shape:
 * {"error": {"code", "message", "details", "request_id"}}.
 * Unexpected failures never expose internals.
 */
final class ErrorEnvelope
{
    public static function render(Throwable $e): JsonResponse
    {
        [$status, $code, $message, $details] = match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->details],
            $e instanceof ValidationException => [422, 'VALIDATION_FAILED', 'Some fields are invalid.', self::fieldErrors($e)],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Authentication is required.', []],
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => [403, 'FORBIDDEN', 'You are not allowed to do this.', []],
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', 'Resource not found.', []],
            $e instanceof ThrottleRequestsException => [429, 'RATE_LIMITED', 'Too many requests. Try again later.', []],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), self::codeFor($e->getStatusCode()), self::messageFor($e), []],
            default => [500, 'INTERNAL_ERROR', 'Something went wrong on our side.', []],
        };

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => Context::get('request_id'),
            ],
        ], $status, $headers);
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private static function fieldErrors(ValidationException $e): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = $e->errors();

        $details = [];
        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = ['field' => $field, 'message' => $message];
            }
        }

        return $details;
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            413 => 'PAYLOAD_TOO_LARGE',
            415 => 'UNSUPPORTED_MEDIA_TYPE',
            503 => 'SERVICE_UNAVAILABLE',
            default => $status >= 500 ? 'INTERNAL_ERROR' : 'HTTP_ERROR',
        };
    }

    private static function messageFor(HttpExceptionInterface&Throwable $e): string
    {
        if ($e->getStatusCode() >= 500) {
            return 'Something went wrong on our side.';
        }

        return $e->getMessage() !== '' ? $e->getMessage() : 'The request could not be processed.';
    }
}
