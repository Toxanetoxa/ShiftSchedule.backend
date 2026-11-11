<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ScheduleApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublishScheduleRequest;
use App\Models\Schedule;
use App\Models\SchedulePublication;
use App\Services\Schedule\PublishSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PublicationController extends Controller
{
    public function store(PublishScheduleRequest $request, string $scheduleId, PublishSchedule $publish): JsonResponse
    {
        $input = $request->validated();
        $result = $publish->execute($scheduleId, $input['expectedVersion'], $input['from'], $input['to'], $request->attributes->get('schedule_actor'));

        return response()->json(PublishSchedule::metadata($result['publication']), $result['created'] ? 201 : 200);
    }

    public function show(string $scheduleId, string $publicationId): JsonResponse
    {
        if (! Schedule::query()->whereKey($scheduleId)->exists()) {
            throw new ScheduleApiException('SCHEDULE_NOT_FOUND', 'График не найден.', 404);
        }
        $publication = Str::isUuid($publicationId)
            ? SchedulePublication::query()->where('schedule_id', $scheduleId)->whereKey($publicationId)->first()
            : null;
        if (! $publication) {
            throw new ScheduleApiException('PUBLICATION_NOT_FOUND', 'Публикация не найдена.', 404);
        }

        return response()->json([...PublishSchedule::metadata($publication), 'snapshot' => $publication->snapshot]);
    }
}
