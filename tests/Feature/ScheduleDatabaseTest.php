<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Schedule;
use App\Models\SchedulePublication;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleDatabaseTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('shiftschedule_testing', config('database.connections.pgsql.database'), 'Tests may migrate only the dedicated PostgreSQL testing database.');
        $this->assertSame('shiftschedule_testing', DB::selectOne('SELECT current_database() AS name')->name);
    }

    public function test_postgresql_enforces_one_shift_per_cell_even_for_nonoverlapping_intervals(): void
    {
        [$schedule, $employee] = $this->member();
        $this->shift($schedule, $employee);
        $this->assertSqlState('23505', fn () => $this->shift($schedule, $employee, ['starts_at' => '2026-11-09T14:00:00Z', 'ends_at' => '2026-11-09T16:00:00Z']), 'shifts_unique_cell');
        $this->assertDatabaseCount('shifts', 1);
    }

    public function test_overlap_is_enforced_across_schedules_for_the_same_employee(): void
    {
        [$first, $employee] = $this->member();
        $second = Schedule::factory()->create();
        $second->employees()->attach($employee->id, ['sort_order' => 0, 'is_active' => true]);
        $this->shift($first, $employee);
        $this->assertSqlState('23P01', fn () => $this->shift($second, $employee, ['starts_at' => '2026-11-09T07:00:00Z', 'ends_at' => '2026-11-09T15:00:00Z']), 'shifts_no_overlap');
        $this->assertDatabaseCount('shifts', 1);
    }

    public function test_overnight_intervals_overlap_across_start_dates_but_touching_boundaries_are_allowed(): void
    {
        [$schedule, $employee] = $this->member();
        $this->shift($schedule, $employee, ['starts_at' => '2026-11-09T19:00:00Z', 'ends_at' => '2026-11-10T03:00:00Z']);
        $this->assertSqlState('23P01', fn () => $this->shift($schedule, $employee, [
            'work_date' => '2026-11-10', 'starts_at' => '2026-11-10T02:00:00Z', 'ends_at' => '2026-11-10T06:00:00Z',
        ]), 'shifts_no_overlap');
        $this->shift($schedule, $employee, ['work_date' => '2026-11-10', 'starts_at' => '2026-11-10T03:00:00Z', 'ends_at' => '2026-11-10T11:00:00Z']);
        $this->assertDatabaseCount('shifts', 2);
    }

    public function test_cell_uniqueness_and_overlap_constraints_are_deferrable_and_allow_a_final_valid_swap(): void
    {
        $constraints = DB::select("SELECT conname, condeferrable, condeferred FROM pg_constraint WHERE conname IN ('shifts_unique_cell', 'shifts_no_overlap')");
        $this->assertCount(2, $constraints);
        foreach ($constraints as $constraint) {
            $this->assertTrue($constraint->condeferrable);
            $this->assertFalse($constraint->condeferred);
        }
        [$schedule, $firstEmployee] = $this->member();
        $secondEmployee = Employee::factory()->create();
        $schedule->employees()->attach($secondEmployee->id, ['sort_order' => 1, 'is_active' => true]);
        $first = $this->shift($schedule, $firstEmployee);
        $second = $this->shift($schedule, $secondEmployee);
        DB::transaction(function () use ($first, $second, $firstEmployee, $secondEmployee): void {
            DB::statement('SET CONSTRAINTS shifts_unique_cell, shifts_no_overlap DEFERRED');
            DB::table('shifts')->where('id', $first->id)->update(['employee_id' => $secondEmployee->id]);
            DB::table('shifts')->where('id', $second->id)->update(['employee_id' => $firstEmployee->id]);
            DB::statement('SET CONSTRAINTS shifts_unique_cell, shifts_no_overlap IMMEDIATE');
        });
        $this->assertDatabaseHas('shifts', ['id' => $first->id, 'employee_id' => $secondEmployee->id]);
        $this->assertDatabaseHas('shifts', ['id' => $second->id, 'employee_id' => $firstEmployee->id]);
    }

    public function test_composite_foreign_keys_reject_an_employee_or_template_from_another_schedule(): void
    {
        [$schedule, $employee] = $this->member();
        $outsider = Employee::factory()->create();
        $this->assertSqlState('23503', fn () => $this->shift($schedule, $outsider), 'shifts_employee_in_schedule_fk');
        $otherTemplate = ShiftTemplate::factory()->create();
        $this->assertSqlState('23503', fn () => $this->shift($schedule, $employee, ['source_template_id' => $otherTemplate->id]), 'shifts_template_in_schedule_fk');
        $this->assertDatabaseCount('shifts', 0);
    }

    #[DataProvider('invalidIntervals')]
    public function test_database_rejects_invalid_shift_intervals(string $start, string $end, string $constraint): void
    {
        [$schedule, $employee] = $this->member();
        $this->assertSqlState('23514', fn () => $this->shift($schedule, $employee, ['starts_at' => $start, 'ends_at' => $end]), $constraint);
        $this->assertDatabaseCount('shifts', 0);
    }

    public static function invalidIntervals(): array
    {
        return [
            ['2026-11-09T06:00:00Z', '2026-11-09T06:00:00Z', 'shifts_duration_valid'],
            ['2026-11-09T06:00:00Z', '2026-11-10T06:00:00Z', 'shifts_duration_valid'],
            ['2026-11-09T06:00:30Z', '2026-11-09T14:00:00Z', 'shifts_minute_precision'],
        ];
    }

    public function test_schedule_time_zone_cannot_change_even_before_any_shift_exists(): void
    {
        $schedule = Schedule::factory()->create(['time_zone' => 'Europe/Moscow']);
        $this->assertSqlState('23514', fn () => DB::table('schedules')->where('id', $schedule->id)->update(['time_zone' => 'UTC']));
        $this->assertSame('Europe/Moscow', $schedule->fresh()->time_zone);
        $schedule->update(['name' => 'Разрешённое новое название']);
        $this->assertSame('Разрешённое новое название', $schedule->fresh()->name);
    }

    public function test_publication_rows_cannot_be_updated_or_deleted_by_direct_sql(): void
    {
        $publication = SchedulePublication::factory()->create();
        $before = $publication->fresh()->getAttributes();
        $this->assertSqlState('55000', fn () => DB::table('schedule_publications')->where('id', $publication->id)->update(['snapshot' => json_encode(['schemaVersion' => 1, 'changed' => true], JSON_THROW_ON_ERROR)]));
        $this->assertSqlState('55000', fn () => DB::table('schedule_publications')->where('id', $publication->id)->delete());
        $this->assertSame($before, $publication->fresh()->getAttributes());
    }

    public function test_publications_are_unique_per_schedule_version_and_period(): void
    {
        $publication = SchedulePublication::factory()->create();
        $attributes = $publication->getRawOriginal();
        $attributes['id'] = (string) Str::uuid();
        $this->assertSqlState('23505', fn () => DB::table('schedule_publications')->insert($attributes), 'schedule_publications_same_version_period_uq');
        $this->assertDatabaseCount('schedule_publications', 1);
    }

    public function test_two_independent_postgresql_connections_serialize_the_schedule_row_lock(): void
    {
        $configuration = config('database.connections.pgsql');
        config(['database.connections.schedule_writer_a' => $configuration, 'database.connections.schedule_writer_b' => $configuration]);
        $first = DB::connection('schedule_writer_a');
        $second = DB::connection('schedule_writer_b');
        $schedule = Schedule::factory()->create();
        try {
            $first->beginTransaction();
            $first->table('schedules')->where('id', $schedule->id)->lockForUpdate()->first();
            $second->statement("SET lock_timeout TO '100ms'");
            try {
                $second->transaction(fn () => $second->table('schedules')->where('id', $schedule->id)->lockForUpdate()->first());
                $this->fail('A second writer acquired the same schedule lock before the first writer committed.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
            $first->table('schedules')->where('id', $schedule->id)->update(['version' => 2]);
            $first->commit();
            $version = $second->transaction(fn () => $second->table('schedules')->where('id', $schedule->id)->lockForUpdate()->value('version'));
            $this->assertSame(2, (int) $version);
            $this->assertNotSame(1, (int) $version, 'The stale expectedVersion must be compared after acquiring the lock.');
        } finally {
            while ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
            while ($second->transactionLevel() > 0) {
                $second->rollBack();
            }
            DB::purge('schedule_writer_a');
            DB::purge('schedule_writer_b');
        }
    }

    public function test_application_initialization_migrates_and_seeds_once_without_overwriting_later_changes(): void
    {
        $this->artisan('migrate:rollback', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('schedules'));
        $this->artisan('app:initialize', ['--no-interaction' => true])->assertSuccessful();
        $schedule = Schedule::findOrFail('00000000-0000-4000-8000-000000000001');
        $employee = Employee::findOrFail('00000000-0000-4000-8000-000000000201');
        $template = ShiftTemplate::findOrFail('00000000-0000-4000-8000-000000000101');
        $schedule->update(['name' => 'Название после первого запуска', 'version' => 9]);
        $employee->update(['full_name' => 'Изменённое имя', 'is_active' => false]);
        $schedule->employees()->updateExistingPivot($employee->id, ['sort_order' => 99, 'is_active' => false]);
        $template->update(['name' => 'Старый шаблон', 'is_active' => false]);
        $shift = $this->shift($schedule, $employee, ['label_snapshot' => 'Сохранённая смена']);
        $publication = SchedulePublication::factory()->create(['schedule_id' => $schedule->id]);
        $shiftBefore = $shift->fresh()->getAttributes();
        $publicationBefore = $publication->fresh()->getAttributes();

        $this->artisan('app:initialize', ['--no-interaction' => true])->assertSuccessful();

        $this->assertDatabaseCount('schedules', 1);
        $this->assertDatabaseCount('employees', 8);
        $this->assertDatabaseCount('schedule_employees', 8);
        $this->assertDatabaseCount('shift_templates', 3);
        $this->assertDatabaseCount('shifts', 1);
        $this->assertDatabaseCount('schedule_publications', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(9, $schedule->fresh()->version);
        $this->assertSame('Название после первого запуска', $schedule->fresh()->name);
        $this->assertSame('Изменённое имя', $employee->fresh()->full_name);
        $this->assertFalse($employee->fresh()->is_active);
        $this->assertDatabaseHas('schedule_employees', ['schedule_id' => $schedule->id, 'employee_id' => $employee->id, 'sort_order' => 99, 'is_active' => false]);
        $this->assertSame('Старый шаблон', $template->fresh()->name);
        $this->assertFalse($template->fresh()->is_active);
        $this->assertSame($shiftBefore, $shift->fresh()->getAttributes());
        $this->assertSame($publicationBefore, $publication->fresh()->getAttributes());
    }

    /** @return array{Schedule, Employee} */
    private function member(): array
    {
        $schedule = Schedule::factory()->create();
        $employee = Employee::factory()->create();
        $schedule->employees()->attach($employee->id, ['sort_order' => 0, 'is_active' => true]);

        return [$schedule, $employee];
    }

    /** @param array<string, mixed> $attributes */
    private function shift(Schedule $schedule, Employee $employee, array $attributes = []): Shift
    {
        return Shift::factory()->create([
            'schedule_id' => $schedule->id, 'employee_id' => $employee->id, 'work_date' => '2026-11-09',
            'starts_at' => '2026-11-09T04:00:00Z', 'ends_at' => '2026-11-09T12:00:00Z',
            'source_template_id' => null, 'label_snapshot' => 'Своя смена', 'color_key_snapshot' => 'custom',
            ...$attributes,
        ]);
    }

    private function assertSqlState(string $state, callable $operation, ?string $constraint = null): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL accepted an operation that should violate the schema.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->getMessage());
            }
        }
    }
}
