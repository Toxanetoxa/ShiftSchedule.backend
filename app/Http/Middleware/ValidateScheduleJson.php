<?php

namespace App\Http\Middleware;

use App\Exceptions\ScheduleApiException;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class ValidateScheduleJson
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['POST', 'PATCH'], true)) {
            if (! $request->isJson()) {
                throw new ScheduleApiException('VALIDATION_FAILED', 'Тело запроса должно быть JSON-объектом.', details: ['field' => 'body']);
            }
            try {
                $body = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ScheduleApiException('INVALID_JSON', 'Не удалось разобрать JSON запроса.', 400);
            }
            if (! is_object($body)) {
                throw new ScheduleApiException('VALIDATION_FAILED', 'Тело запроса должно быть JSON-объектом.', details: ['field' => 'body']);
            }
            if (property_exists($body, 'changes') && ! is_array($body->changes)) {
                throw new ScheduleApiException('VALIDATION_FAILED', 'changes должен быть массивом ячеек.', details: ['field' => 'changes']);
            }
        }

        return $next($request);
    }
}
