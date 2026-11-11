<?php

namespace App\Http\Middleware;

use App\Exceptions\ScheduleApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AccessDemoSchedule
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $scheduleId = (string) $request->route('scheduleId');
        if (! config('schedule.demo_mode') || strtolower($scheduleId) !== config('schedule.demo_schedule_id')) {
            throw new ScheduleApiException('SCHEDULE_NOT_FOUND', 'График не найден.', 404);
        }

        $request->attributes->set('schedule_actor', 'demo-manager');

        return $next($request);
    }
}
