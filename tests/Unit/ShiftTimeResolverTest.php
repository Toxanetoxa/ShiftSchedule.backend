<?php

namespace Tests\Unit;

use App\Exceptions\ScheduleApiException;
use App\Services\Schedule\ShiftTimeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShiftTimeResolverTest extends TestCase
{
    public function test_moscow_night_shift_uses_the_start_date_and_exact_utc_instants(): void
    {
        $previousZone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            $result = (new ShiftTimeResolver)->resolve('2026-11-02', '22:00', '06:00', 1, 'Europe/Moscow');
            $this->assertSame('2026-11-02T19:00:00Z', $result['startsAt']->format('Y-m-d\TH:i:s\Z'));
            $this->assertSame('2026-11-03T03:00:00Z', $result['endsAt']->format('Y-m-d\TH:i:s\Z'));
            $this->assertSame(480, $result['durationMinutes']);
        } finally {
            date_default_timezone_set($previousZone);
        }
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_ambiguous_local_times_are_rejected(string $date, string $start, string $end, int $offset, string $zone): void
    {
        try {
            (new ShiftTimeResolver)->resolve($date, $start, $end, $offset, $zone);
            $this->fail('An invalid local shift time was silently accepted or normalized.');
        } catch (ScheduleApiException $exception) {
            $this->assertSame('INVALID_SHIFT_TIME', $exception->errorCode);
            $this->assertSame(422, $exception->status);
        }
    }

    public static function invalidInputs(): array
    {
        return [
            'equal same day' => ['2026-11-09', '09:00', '09:00', 0, 'Europe/Moscow'],
            'equal next day' => ['2026-11-09', '09:00', '09:00', 1, 'Europe/Moscow'],
            'reverse without offset' => ['2026-11-09', '17:00', '09:00', 0, 'Europe/Moscow'],
            'longer than one day' => ['2026-11-09', '09:00', '17:00', 1, 'Europe/Moscow'],
            'invalid offset' => ['2026-11-09', '09:00', '17:00', 2, 'Europe/Moscow'],
            'seconds forbidden' => ['2026-11-09', '09:00:30', '17:00', 0, 'Europe/Moscow'],
            '24 oclock forbidden' => ['2026-11-09', '24:00', '17:00', 0, 'Europe/Moscow'],
            'nonexistent date' => ['2026-02-30', '09:00', '17:00', 0, 'Europe/Moscow'],
            'unknown zone' => ['2026-11-09', '09:00', '17:00', 0, 'Unknown/Timezone'],
            'DST gap' => ['2026-03-29', '02:30', '04:30', 0, 'Europe/Berlin'],
            'DST ambiguity' => ['2026-10-25', '02:30', '04:30', 0, 'Europe/Berlin'],
        ];
    }

    public function test_unique_endpoints_crossing_daylight_saving_use_actual_elapsed_minutes(): void
    {
        $result = (new ShiftTimeResolver)->resolve('2026-03-29', '01:00', '04:00', 0, 'Europe/Berlin');
        $this->assertSame('2026-03-29T00:00:00Z', $result['startsAt']->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-03-29T02:00:00Z', $result['endsAt']->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(120, $result['durationMinutes']);
    }
}
