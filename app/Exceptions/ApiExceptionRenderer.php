<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ApiExceptionRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => self::validation($e),
            $e instanceof AuthenticationException => self::unauthenticated(),
            $e instanceof AuthorizationException => self::forbidden($e->getMessage()),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => self::notFound(),
            $e instanceof HttpExceptionInterface => self::httpException($e),
            default => self::generic($e),
        };
    }

    private static function validation(ValidationException $e): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'Los datos enviados no son válidos.',
                'details' => ['errors' => $e->errors()],
            ],
        ], 422);
    }

    private static function unauthenticated(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'UNAUTHENTICATED',
                'message' => 'No autenticado.',
            ],
        ], 401);
    }

    private static function forbidden(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => $message !== '' ? $message : 'Acción no autorizada.',
            ],
        ], 403);
    }

    private static function notFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => 'Recurso no encontrado.',
            ],
        ], 404);
    }

    private static function httpException(HttpExceptionInterface $e): JsonResponse
    {
        $message = $e->getMessage() ?: 'Error de petición.';
        $code = match (true) {
            $e->getStatusCode() === 409 && str_contains($message, 'Stock insuficiente') => 'INSUFFICIENT_STOCK',
            $e->getStatusCode() === 409 && str_contains($message, 'no se puede anular') => 'MOVEMENT_NOT_VOIDABLE',
            default => 'HTTP_'.$e->getStatusCode(),
        };

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $e->getStatusCode());
    }

    private static function generic(Throwable $e): JsonResponse
    {
        $status = 500;
        $payload = [
            'error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => 'Error interno del servidor.',
            ],
        ];

        if (config('app.debug')) {
            $payload['error']['details'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return response()->json($payload, $status);
    }
}
