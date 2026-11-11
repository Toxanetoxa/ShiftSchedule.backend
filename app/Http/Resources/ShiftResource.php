<?php

namespace App\Http\Resources;

use App\Models\Schedule;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

class ShiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $shift = $this->resource;
        if (! $shift instanceof Shift || ! $shift->relationLoaded('schedule')) {
            throw new LogicException('ShiftResource requires a Shift with an explicitly loaded schedule.');
        }

        $schedule = $shift->getRelation('schedule');
        if (! $schedule instanceof Schedule) {
            throw new LogicException('ShiftResource requires a schedule context.');
        }

        return self::payload($shift, $schedule);
    }

    /**
     * @return array{id: string, employeeId: string, workDate: string, startTime: string, endTime: string,
     *     endDayOffset: int, startsAt: string, endsAt: string, durationMinutes: int,
     *     sourceTemplateId: ?string, label: string, colorKey: string}
     */
    public static function payload(Shift $shift, Schedule $schedule): array
    {
        $startsAt = CarbonImmutable::instance($shift->starts_at)->setTimezone('UTC');
        $endsAt = CarbonImmutable::instance($shift->ends_at)->setTimezone('UTC');
        $localStart = $startsAt->setTimezone($schedule->time_zone);
        $localEnd = $endsAt->setTimezone($schedule->time_zone);

        return [
            'id' => $shift->id,
            'employeeId' => $shift->employee_id,
            'workDate' => $shift->work_date->format('Y-m-d'),
            'startTime' => $localStart->format('H:i'),
            'endTime' => $localEnd->format('H:i'),
            'endDayOffset' => $localStart->format('Y-m-d') === $localEnd->format('Y-m-d') ? 0 : 1,
            'startsAt' => $startsAt->format('Y-m-d\TH:i:s\Z'),
            'endsAt' => $endsAt->format('Y-m-d\TH:i:s\Z'),
            'durationMinutes' => intdiv($endsAt->getTimestamp() - $startsAt->getTimestamp(), 60),
            'sourceTemplateId' => $shift->source_template_id,
            'label' => $shift->label_snapshot,
            'colorKey' => $shift->color_key_snapshot,
        ];
    }
}
