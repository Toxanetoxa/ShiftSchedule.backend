<?php

namespace App\Services\Schedule;

use App\Exceptions\ScheduleApiException;
use App\Models\Schedule;
use App\Models\SchedulePublication;
use Illuminate\Support\Facades\DB;

class PublishSchedule
{
    public function __construct(private CalendarQuery $calendar) {}

    /** @return array{publication: SchedulePublication, created: bool} */
    public function execute(string $scheduleId, int $expectedVersion, string $from, string $to, string $actor): array
    {
        return DB::transaction(function () use ($scheduleId, $expectedVersion, $from, $to, $actor): array {
            $schedule = Schedule::query()->whereKey($scheduleId)->lockForUpdate()->first();
            if (! $schedule) {
                throw new ScheduleApiException('SCHEDULE_NOT_FOUND', 'График не найден.', 404);
            }
            $existing = SchedulePublication::query()->where('schedule_id', $schedule->id)
                ->where('from_date', $from)->where('to_date', $to)->where('source_version', $expectedVersion)->first();
            if ($existing) {
                return ['publication' => $existing, 'created' => false];
            }
            if ($schedule->version !== $expectedVersion) {
                throw new ScheduleApiException('SCHEDULE_VERSION_CONFLICT', 'График изменён в другой сессии. Обновите данные перед публикацией.', 409, [
                    'expectedVersion' => $expectedVersion, 'currentVersion' => $schedule->version,
                ]);
            }
            $calendar = $this->calendar->forSchedule($schedule, $from, $to);
            unset($calendar['shiftTemplates']);
            $publication = new SchedulePublication;
            $publication->forceFill([
                'schedule_id' => $schedule->id, 'from_date' => $from, 'to_date' => $to,
                'source_version' => $schedule->version, 'snapshot_schema_version' => 1,
                'snapshot' => ['schemaVersion' => 1, ...$calendar], 'created_by' => $actor,
            ])->save();

            return ['publication' => $publication, 'created' => true];
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public static function metadata(SchedulePublication $publication): array
    {
        return [
            'id' => $publication->id, 'scheduleId' => $publication->schedule_id,
            'range' => ['from' => $publication->from_date->format('Y-m-d'), 'to' => $publication->to_date->format('Y-m-d')],
            'sourceVersion' => $publication->source_version,
            'publishedAt' => $publication->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
