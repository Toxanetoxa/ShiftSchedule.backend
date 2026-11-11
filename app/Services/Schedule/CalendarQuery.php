<?php

namespace App\Services\Schedule;

use App\Exceptions\ScheduleApiException;
use App\Http\Resources\ShiftResource;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CalendarQuery
{
    /**
     * @return array<string, mixed>
     */
    public function get(string $scheduleId, string $from, string $to): array
    {
        if (! Str::isUuid($scheduleId)) {
            throw new ScheduleApiException('SCHEDULE_NOT_FOUND', 'График не найден.', 404);
        }

        return DB::transaction(function () use ($scheduleId, $from, $to): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $schedule = Schedule::query()->find($scheduleId);
            if (! $schedule) {
                throw new ScheduleApiException('SCHEDULE_NOT_FOUND', 'График не найден.', 404);
            }

            return $this->forSchedule($schedule, $from, $to);
        });
    }

    /**
     * Build the same DTO inside the caller's transaction when publishing a locked schedule.
     *
     * @return array<string, mixed>
     */
    public function forSchedule(Schedule $schedule, string $from, string $to): array
    {
        $employees = $schedule->employees()->orderByPivot('sort_order')->orderBy('employees.id')->get();
        $templates = $schedule->shiftTemplates()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $shifts = $schedule->shifts()->where('work_date', '>=', $from)->where('work_date', '<', $to)
            ->orderBy('work_date')->orderBy('employee_id')->orderBy('id')->get();
        $shiftPayloads = $shifts->map(fn (Shift $shift): array => ShiftResource::payload($shift, $schedule))->all();
        $minutesByEmployee = [];
        $countsByDate = [];
        foreach ($shiftPayloads as $shift) {
            $minutesByEmployee[$shift['employeeId']] = ($minutesByEmployee[$shift['employeeId']] ?? 0) + $shift['durationMinutes'];
            $countsByDate[$shift['workDate']] = ($countsByDate[$shift['workDate']] ?? 0) + 1;
        }

        $coverage = [];
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');
        for ($date = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC'); $date->lessThan($end); $date = $date->addDay()) {
            $key = $date->format('Y-m-d');
            $count = $countsByDate[$key] ?? 0;
            $coverage[] = ['date' => $key, 'shiftCount' => $count, 'covered' => $count > 0];
        }

        return [
            'schedule' => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'timeZone' => $schedule->time_zone,
                'version' => (int) $schedule->version,
            ],
            'range' => ['from' => $from, 'to' => $to],
            'employees' => $employees->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'fullName' => $employee->full_name,
                'positionTitle' => $employee->position_title,
                'avatarUrl' => $employee->avatar_url,
                'scheduledMinutes' => $minutesByEmployee[$employee->id] ?? 0,
                'isActive' => (bool) $employee->is_active && (bool) $employee->pivot->is_active,
            ])->all(),
            'shiftTemplates' => $templates->map(fn (ShiftTemplate $template): array => $this->templatePayload($template))->all(),
            'shifts' => $shiftPayloads,
            'coverage' => $coverage,
        ];
    }

    /**
     * @return array{id: string, name: string, startTime: string, endTime: string, endDayOffset: int, durationMinutes: int, colorKey: string}
     */
    private function templatePayload(ShiftTemplate $template): array
    {
        $start = substr($template->start_time, 0, 5);
        $end = substr($template->end_time, 0, 5);
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));

        return [
            'id' => $template->id,
            'name' => $template->name,
            'startTime' => $start,
            'endTime' => $end,
            'endDayOffset' => (int) $template->end_day_offset,
            'durationMinutes' => $endHour * 60 + $endMinute + $template->end_day_offset * 1440 - $startHour * 60 - $startMinute,
            'colorKey' => $template->color_key,
        ];
    }
}
