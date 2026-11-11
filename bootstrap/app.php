<?php

use App\Exceptions\ScheduleApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
        then: function (): void {
            require __DIR__.'/../routes/docs.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi('api');

        $isScheduleApi = fn (Request $request): bool => $request->is('api/v1/schedules/*');
        $middleware->trimStrings(except: [$isScheduleApi]);
        $middleware->convertEmptyStringsToNull(except: [$isScheduleApi]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);
        $exceptions->dontReport([ScheduleApiException::class]);
        $exceptions->render(function (ThrottleRequestsException $exception): JsonResponse {
            $headers = $exception->getHeaders();

            return response()->json(['error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Слишком много запросов. Повторите попытку позже.',
                'details' => ['retryAfter' => (int) ($headers['Retry-After'] ?? 1)],
            ]], 429, $headers);
        });
        $exceptions->render(function (ScheduleApiException $exception): JsonResponse {
            return response()->json(['error' => [
                'code' => $exception->errorCode, 'message' => $exception->getMessage(), 'details' => (object) $exception->details,
            ]], $exception->status);
        });
        $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/v1/schedules/*')) {
                return null;
            }
            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                return response()->json(['error' => [
                    'code' => 'HTTP_ERROR', 'message' => 'Запрос недоступен.', 'details' => (object) [],
                ]], $exception->getStatusCode());
            }
            $requestId = (string) Str::uuid();
            Log::error('Schedule API request failed.', ['requestId' => $requestId, 'exception' => $exception]);

            return response()->json(['error' => [
                'code' => 'INTERNAL_ERROR', 'message' => 'Не удалось выполнить запрос.', 'details' => ['requestId' => $requestId],
            ]], 500);
        });
    })->create();
