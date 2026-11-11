<?php

namespace App\Services\Schedule;

use App\Exceptions\ScheduleApiException;
use App\Http\Resources\ShiftResource;
use App\Models\Schedule;
use App\Models\ScheduleEmployee;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveScheduleCells
{
    public function __construct(private ShiftTimeResolver $timeResolver) {}

    /**
     * @param  array<int, array{employeeId: string, workDate: string, shift: ?array{startTime: string, endTime: string, endDayOffset: int, sourceTemplateId: ?string}}>  $changes
     * @return array{version: int, changedCells: array<int, array<string, mixed>>}
     */
    public function execute(string $scheduleId, int $expectedVersion, array $changes): array
    {
        try {
            return DB::transaction(function () use ($scheduleId, $expectedVersion, $changes): array {
                $schedule = Schedule::query()->whereKey($scheduleId)->lockForUpdate()->first();
                if (! $schedule) {
                    throw new ScheduleApiException('SCHEDULE_NOT_FOUND', 'График не найден.', 404);
                }
                if ($schedule->version !== $expectedVersion) {
                    throw new ScheduleApiException('SCHEDULE_VERSION_CONFLICT', 'График изменён в другой сессии. Обновите данные перед сохранением.', 409, [
                        'expectedVersion' => $expectedVersion, 'currentVersion' => $schedule->version,
                    ]);
                }

                $employeeIds = array_unique(array_map(fn (array $change): string => strtolower($change['employeeId']), $changes));
                $members = ScheduleEmployee::query()->with('employee')->where('schedule_id', $schedule->id)
                    ->whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id');
                $templateIds = array_values(array_unique(array_filter(array_map(
                    fn (array $change): ?string => isset($change['shift']['sourceTemplateId']) ? strtolower($change['shift']['sourceTemplateId']) : null,
                    $changes,
                ))));
                $templates = ShiftTemplate::query()->where('schedule_id', $schedule->id)->whereIn('id', $templateIds)->get()->keyBy('id');
                $existing = Shift::query()->where('schedule_id', $schedule->id)->whereIn('employee_id', $employeeIds)
                    ->whereIn('work_date', array_column($changes, 'workDate'))->get()
                    ->keyBy(fn (Shift $shift): string => $shift->employee_id.':'.$shift->work_date->format('Y-m-d'));

                $prepared = [];
                foreach ($changes as $index => $change) {
                    $employeeId = strtolower($change['employeeId']);
                    $key = $employeeId.':'.$change['workDate'];
                    $details = ['employeeId' => $employeeId, 'workDate' => $change['workDate'], 'field' => "changes.$index.shift"];
                    $member = $members->get($employeeId);
                    if (! $member) {
                        throw new ScheduleApiException('EMPLOYEE_NOT_IN_SCHEDULE', 'Сотрудник не включён в график.', details: [...$details, 'field' => "changes.$index.employeeId"]);
                    }
                    $attributes = null;
                    if ($change['shift'] !== null) {
                        $input = $change['shift'];
                        try {
                            $resolved = $this->timeResolver->resolve($change['workDate'], $input['startTime'], $input['endTime'], $input['endDayOffset'], $schedule->time_zone);
                        } catch (ScheduleApiException $exception) {
                            throw new ScheduleApiException($exception->errorCode, $exception->getMessage(), $exception->status, [...$exception->details, ...$details]);
                        }
                        $templateId = $input['sourceTemplateId'] !== null ? strtolower($input['sourceTemplateId']) : null;
                        $template = $templateId !== null ? $templates->get($templateId) : null;
                        if ($templateId !== null && ! $template) {
                            throw new ScheduleApiException('TEMPLATE_NOT_FOUND', 'Шаблон не найден в этом графике.', details: [...$details, 'field' => "changes.$index.shift.sourceTemplateId"]);
                        }
                        if ($template && ! $template->is_active) {
                            throw new ScheduleApiException('TEMPLATE_INACTIVE', 'Шаблон недоступен для новых назначений.', details: [...$details, 'field' => "changes.$index.shift.sourceTemplateId"]);
                        }
                        if ($template && (substr($template->start_time, 0, 5) !== $input['startTime'] || substr($template->end_time, 0, 5) !== $input['endTime'] || $template->end_day_offset !== $input['endDayOffset'])) {
                            throw new ScheduleApiException('TEMPLATE_VALUES_MISMATCH', 'Время смены не совпадает с выбранным шаблоном.', details: $details);
                        }
                        $attributes = [
                            'starts_at' => $resolved['startsAt'], 'ends_at' => $resolved['endsAt'],
                            'source_template_id' => $templateId, 'label_snapshot' => $template?->name ?? 'Своя смена',
                            'color_key_snapshot' => $template?->color_key ?? 'custom',
                        ];
                    }
                    $current = $existing->get($key);
                    $changed = ! $this->sameAssignment($current, $attributes);
                    if ($attributes !== null && $changed && (! $member->is_active || ! $member->employee->is_active)) {
                        throw new ScheduleApiException('EMPLOYEE_INACTIVE', 'Сотрудник или его участие в графике неактивны.', details: $details);
                    }
                    $prepared[$key] = [
                        'employeeId' => $employeeId, 'workDate' => $change['workDate'], 'attributes' => $attributes,
                        'current' => $current, 'changed' => $changed, 'details' => $details,
                    ];
                }

                $hasChanges = count(array_filter($prepared, fn (array $cell): bool => $cell['changed'])) > 0;
                if ($hasChanges) {
                    $this->assertNoOverlaps($schedule, $prepared);
                    DB::statement('SET CONSTRAINTS shifts_unique_cell, shifts_no_overlap DEFERRED');
                    foreach ($prepared as &$cell) {
                        if (! $cell['changed']) {
                            continue;
                        }
                        if ($cell['attributes'] === null) {
                            $cell['current']?->delete();
                            $cell['current'] = null;
                        } else {
                            $shift = $cell['current'] ?? new Shift;
                            $shift->forceFill([
                                'schedule_id' => $schedule->id, 'employee_id' => $cell['employeeId'], 'work_date' => $cell['workDate'],
                                ...$cell['attributes'],
                            ])->save();
                            $cell['current'] = $shift;
                        }
                    }
                    unset($cell);
                    DB::statement('SET CONSTRAINTS shifts_unique_cell, shifts_no_overlap IMMEDIATE');
                    $schedule->version++;
                    $schedule->save();
                }

                return [
                    'version' => $schedule->version,
                    'changedCells' => array_values(array_map(fn (array $cell): array => [
                        'employeeId' => $cell['employeeId'], 'workDate' => $cell['workDate'],
                        'shift' => $cell['current'] ? Arr::except(ShiftResource::payload($cell['current'], $schedule), ['employeeId', 'workDate']) : null,
                    ], $prepared)),
                ];
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23P01') {
                throw new ScheduleApiException('SHIFT_OVERLAP', 'Смена пересекается с другим назначением сотрудника.', 409);
            }
            if (in_array($exception->errorInfo[0] ?? null, ['23503', '23514'], true)) {
                throw new ScheduleApiException('VALIDATION_FAILED', 'Назначение не соответствует ограничениям графика.');
            }
            throw $exception;
        }
    }

    /** @param ?array<string, mixed> $attributes */
    private function sameAssignment(?Shift $current, ?array $attributes): bool
    {
        if ($current === null || $attributes === null) {
            return $current === null && $attributes === null;
        }

        return $current->starts_at->getTimestamp() === $attributes['starts_at']->getTimestamp()
            && $current->ends_at->getTimestamp() === $attributes['ends_at']->getTimestamp()
            && $current->source_template_id === $attributes['source_template_id']
            && $current->label_snapshot === $attributes['label_snapshot']
            && $current->color_key_snapshot === $attributes['color_key_snapshot'];
    }

    /** @param array<string, array<string, mixed>> $prepared */
    private function assertNoOverlaps(Schedule $schedule, array $prepared): void
    {
        $candidates = array_filter($prepared, fn (array $cell): bool => $cell['attributes'] !== null);
        if (! $candidates) {
            return;
        }
        $starts = array_map(fn (array $cell): CarbonImmutable => $cell['attributes']['starts_at'], $candidates);
        $ends = array_map(fn (array $cell): CarbonImmutable => $cell['attributes']['ends_at'], $candidates);
        $others = Shift::query()->whereIn('employee_id', array_unique(array_column($candidates, 'employeeId')))
            ->where('starts_at', '<', max($ends)->format('Y-m-d H:i:sP'))
            ->where('ends_at', '>', min($starts)->format('Y-m-d H:i:sP'))->get();
        $intervals = [];
        foreach ($others as $shift) {
            $key = $shift->employee_id.':'.$shift->work_date->format('Y-m-d');
            if ($shift->schedule_id === $schedule->id && isset($prepared[$key])) {
                continue;
            }
            $intervals[$shift->employee_id][] = [
                'start' => $shift->starts_at->getTimestamp(), 'end' => $shift->ends_at->getTimestamp(),
                'workDate' => $shift->work_date->format('Y-m-d'), 'details' => null,
            ];
        }
        foreach ($candidates as $cell) {
            $intervals[$cell['employeeId']][] = [
                'start' => $cell['attributes']['starts_at']->getTimestamp(), 'end' => $cell['attributes']['ends_at']->getTimestamp(),
                'workDate' => $cell['workDate'], 'details' => $cell['details'],
            ];
        }
        foreach ($intervals as $items) {
            usort($items, fn (array $left, array $right): int => $left['start'] <=> $right['start']);
            $previous = null;
            foreach ($items as $item) {
                if ($previous && $item['start'] < $previous['end']) {
                    $details = $item['details'] ?? $previous['details'] ?? [];
                    throw new ScheduleApiException('SHIFT_OVERLAP', 'Смена пересекается с другим назначением сотрудника.', 409, [
                        ...$details, 'conflictingWorkDate' => $item['details'] ? $previous['workDate'] : $item['workDate'],
                    ]);
                }
                $previous = $item;
            }
        }
    }
}
