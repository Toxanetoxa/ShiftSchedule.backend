<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarRequest;
use App\Services\Schedule\CalendarQuery;
use Illuminate\Http\JsonResponse;

class CalendarController extends Controller
{
    public function __construct(private readonly CalendarQuery $calendar) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(CalendarRequest $request, string $scheduleId): JsonResponse
    {
        return response()->json($this->calendar->get($scheduleId, $request->validated('from'), $request->validated('to')));
    }
}
