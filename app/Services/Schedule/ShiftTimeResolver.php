<?php

namespace App\Services\Schedule;

use App\Exceptions\ScheduleApiException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

class ShiftTimeResolver
{
    /**
     * @return array{startsAt: CarbonImmutable, endsAt: CarbonImmutable, durationMinutes: int}
     */
    public function resolve(string $workDate, string $start, string $end, int $offset, string $zone): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $workDate)
            || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $start)
            || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $end)
            || ! in_array($offset, [0, 1], true)) {
            $this->invalid('Дата и время смены должны иметь формат YYYY-MM-DD и HH:mm.');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $workDate));
        if (! checkdate($month, $day, $year)) {
            $this->invalid('Дата начала смены не существует.');
        }

        $startMinutes = $this->minutes($start);
        $endMinutes = $this->minutes($end);
        $wallDuration = $endMinutes + $offset * 1440 - $startMinutes;
        if ($start === $end || $wallDuration < 1 || $wallDuration >= 1440) {
            $this->invalid('Длительность смены должна быть больше нуля и меньше 24 часов.');
        }

        try {
            $timeZone = new DateTimeZone($zone);
        } catch (Exception) {
            $this->invalid('Часовой пояс графика не поддерживается.');
        }

        $endDate = CarbonImmutable::createFromFormat('!Y-m-d', $workDate, 'UTC')->addDays($offset)->format('Y-m-d');
        $startsAt = $this->localInstant($workDate, $start, $timeZone);
        $endsAt = $this->localInstant($endDate, $end, $timeZone);
        $durationSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();

        if ($durationSeconds < 60 || $durationSeconds >= 86400 || $durationSeconds % 60 !== 0) {
            $this->invalid('Фактическая длительность смены должна быть от 1 до 1439 минут.');
        }

        return [
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'durationMinutes' => intdiv($durationSeconds, 60),
        ];
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
     * Enumerate possible offsets instead of accepting PHP's silent DST normalization.
     */
    private function localInstant(string $date, string $time, DateTimeZone $zone): CarbonImmutable
    {
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, new DateTimeZone('UTC'));
        if ($wall === false || $wall->format('Y-m-d H:i') !== $date.' '.$time) {
            $this->invalid('Локальное время смены не существует.');
        }

        $wallTimestamp = $wall->getTimestamp();
        $offsets = [$zone->getOffset($wall)];
        $transitions = $zone->getTransitions($wallTimestamp - 172800, $wallTimestamp + 172800);
        foreach ($transitions ?: [] as $transition) {
            $offsets[] = $transition['offset'];
        }

        $candidates = [];
        foreach (array_unique($offsets) as $offset) {
            $instant = CarbonImmutable::createFromTimestampUTC($wallTimestamp - $offset);
            if ($instant->setTimezone($zone)->format('Y-m-d H:i') === $date.' '.$time) {
                $candidates[$instant->getTimestamp()] = $instant;
            }
        }

        if (count($candidates) !== 1) {
            $this->invalid('Локальное время смены неоднозначно или не существует из-за смены часового пояса.');
        }

        $instant = array_values($candidates)[0];
        if ($instant->second !== 0 || $instant->year < 1 || $instant->year > 9999) {
            $this->invalid('Время смены не может быть представлено с точностью до минуты.');
        }

        return $instant;
    }

    private function invalid(string $message): never
    {
        throw new ScheduleApiException('INVALID_SHIFT_TIME', $message, 422);
    }
}
