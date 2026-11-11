<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleApiTest extends TestCase
{
    use DatabaseMigrations;

    private const SCHEDULE = '00000000-0000-4000-8000-000000000001';

    private const FIRST_EMPLOYEE = '00000000-0000-4000-8000-000000000201';

    private const SECOND_EMPLOYEE = '00000000-0000-4000-8000-000000000202';

    private const MORNING = '00000000-0000-4000-8000-000000000101';

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('shiftschedule_testing', config('database.connections.pgsql.database'), 'Tests may migrate only the dedicated PostgreSQL testing database.');
        $this->assertSame('shiftschedule_testing', DB::selectOne('SELECT current_database() AS name')->name);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['schedule.demo_mode' => true, 'schedule.demo_schedule_id' => self::SCHEDULE]);
        $this->seed(DatabaseSeeder::class);
    }

    public function test_empty_calendar_contains_the_complete_ordered_roster_templates_and_zero_coverage(): void
    {
        $response = $this->calendar()->assertOk()->assertJsonPath('schedule.id', self::SCHEDULE)
            ->assertJsonPath('schedule.version', 1)->assertJsonPath('schedule.timeZone', 'Europe/Moscow')
            ->assertJsonPath('range', ['from' => '2026-11-09', 'to' => '2026-11-16'])
            ->assertJsonCount(8, 'employees')->assertJsonCount(3, 'shiftTemplates')
            ->assertJsonCount(0, 'shifts')->assertJsonCount(7, 'coverage');

        $this->assertEqualsCanonicalizing(['schedule', 'range', 'employees', 'shiftTemplates', 'shifts', 'coverage'], array_keys($response->json()));
        $this->assertSame(self::FIRST_EMPLOYEE, $response->json('employees.0.id'));
        $this->assertSame('Кузнецова Алина', $response->json('employees.0.fullName'));
        $this->assertSame([self::MORNING, '00000000-0000-4000-8000-000000000102', '00000000-0000-4000-8000-000000000103'], array_column($response->json('shiftTemplates'), 'id'));
        foreach ($response->json('employees') as $employee) {
            $this->assertSame(0, $employee['scheduledMinutes']);
            $this->assertTrue($employee['isActive']);
        }
        foreach ($response->json('coverage') as $coverage) {
            $this->assertSame(0, $coverage['shiftCount']);
            $this->assertFalse($coverage['covered']);
        }
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('schedule_publications', 0);
    }

    public function test_custom_shift_is_normalized_to_utc_and_persists_after_a_new_calendar_request(): void
    {
        $response = $this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->custom('10:00', '23:00'))])
            ->assertOk()->assertJsonPath('version', 2)->assertJsonCount(1, 'changedCells')
            ->assertJsonPath('changedCells.0.shift.startTime', '10:00')->assertJsonPath('changedCells.0.shift.endTime', '23:00')
            ->assertJsonPath('changedCells.0.shift.startsAt', '2026-11-09T07:00:00Z')
            ->assertJsonPath('changedCells.0.shift.endsAt', '2026-11-09T20:00:00Z')
            ->assertJsonPath('changedCells.0.shift.durationMinutes', 780)
            ->assertJsonPath('changedCells.0.shift.sourceTemplateId', null)
            ->assertJsonPath('changedCells.0.shift.label', 'Своя смена')->assertJsonPath('changedCells.0.shift.colorKey', 'custom');
        $this->assertTrue(Str::isUuid($response->json('changedCells.0.shift.id')));
        $this->calendar()->assertOk()->assertJsonPath('schedule.version', 2)->assertJsonCount(1, 'shifts')
            ->assertJsonPath('employees.0.scheduledMinutes', 780)->assertJsonPath('coverage.0.shiftCount', 1);
    }

    public function test_night_shifts_belong_to_the_start_date_and_calendar_ranges_are_half_open(): void
    {
        $this->patchCells(1, [
            $this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->custom('22:00', '06:00', 1)),
            $this->change(self::FIRST_EMPLOYEE, '2026-11-10', $this->custom('06:00', '14:00')),
        ])->assertOk();

        $this->calendar('2026-11-09', '2026-11-10')->assertOk()->assertJsonCount(1, 'shifts')->assertJsonCount(1, 'coverage')
            ->assertJsonPath('shifts.0.workDate', '2026-11-09')->assertJsonPath('shifts.0.endDayOffset', 1)
            ->assertJsonPath('shifts.0.startsAt', '2026-11-09T19:00:00Z')->assertJsonPath('shifts.0.endsAt', '2026-11-10T03:00:00Z')
            ->assertJsonPath('employees.0.scheduledMinutes', 480)->assertJsonPath('coverage.0.shiftCount', 1);
        $this->calendar('2026-11-10', '2026-11-11')->assertOk()->assertJsonCount(1, 'shifts')
            ->assertJsonPath('shifts.0.startTime', '06:00')->assertJsonPath('employees.0.scheduledMinutes', 480);
    }

    public function test_postgresql_session_time_zone_does_not_change_moscow_shift_instants_or_work_date(): void
    {
        DB::statement("SET TIME ZONE 'Pacific/Honolulu'");
        try {
            $this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->morning())])
                ->assertOk()->assertJsonPath('changedCells.0.workDate', '2026-11-09')
                ->assertJsonPath('changedCells.0.shift.startsAt', '2026-11-09T04:00:00Z')
                ->assertJsonPath('changedCells.0.shift.endsAt', '2026-11-09T12:00:00Z');
            $this->calendar('2026-11-09', '2026-11-10')->assertOk()->assertJsonCount(1, 'shifts')
                ->assertJsonPath('shifts.0.workDate', '2026-11-09')->assertJsonPath('shifts.0.startTime', '07:00')
                ->assertJsonPath('shifts.0.startsAt', '2026-11-09T04:00:00Z')
                ->assertJsonPath('shifts.0.endsAt', '2026-11-09T12:00:00Z')
                ->assertJsonPath('shifts.0.durationMinutes', 480);
        } finally {
            DB::statement('RESET TIME ZONE');
        }
    }

    public function test_assigning_a_template_copies_its_values_and_later_reference_edits_do_not_rewrite_the_shift(): void
    {
        $this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->morning())])
            ->assertOk()->assertJsonPath('changedCells.0.shift.sourceTemplateId', self::MORNING)
            ->assertJsonPath('changedCells.0.shift.label', 'Утро')->assertJsonPath('changedCells.0.shift.colorKey', 'morning');
        DB::table('shift_templates')->where('id', self::MORNING)->update(['name' => 'Другое название', 'start_time' => '08:00', 'end_time' => '16:00']);
        $this->calendar()->assertOk()->assertJsonPath('shifts.0.label', 'Утро')->assertJsonPath('shifts.0.startTime', '07:00')
            ->assertJsonPath('shifts.0.endTime', '15:00')->assertJsonPath('shifts.0.durationMinutes', 480);
    }

    public function test_a_patch_changes_only_named_cells_and_returns_explicitly_cleared_cells(): void
    {
        $this->patchCells(1, [
            $this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->morning()),
            $this->change(self::SECOND_EMPLOYEE, '2026-11-09', $this->morning()),
        ])->assertOk();
        $this->patchCells(2, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', null)])
            ->assertOk()->assertJsonPath('version', 3)->assertExactJson([
                'version' => 3,
                'changedCells' => [['employeeId' => self::FIRST_EMPLOYEE, 'workDate' => '2026-11-09', 'shift' => null]],
            ]);
        $this->assertDatabaseCount('shifts', 1);
        $this->assertDatabaseHas('shifts', ['schedule_id' => self::SCHEDULE, 'employee_id' => self::SECOND_EMPLOYEE, 'work_date' => '2026-11-09']);
    }

    public function test_moving_and_swapping_assignments_are_atomic_even_if_an_intermediate_state_would_overlap(): void
    {
        $first = $this->custom('08:00', '16:00');
        $second = $this->custom('10:00', '18:00');
        $this->patchCells(1, [
            $this->change(self::FIRST_EMPLOYEE, '2026-11-09', $first),
            $this->change(self::SECOND_EMPLOYEE, '2026-11-09', $second),
        ])->assertOk();
        $this->patchCells(2, [
            $this->change(self::FIRST_EMPLOYEE, '2026-11-09', $second),
            $this->change(self::SECOND_EMPLOYEE, '2026-11-09', $first),
        ])->assertOk()->assertJsonPath('version', 3)->assertJsonCount(2, 'changedCells');
        $this->patchCells(3, [
            $this->change(self::SECOND_EMPLOYEE, '2026-11-10', $second),
            $this->change(self::FIRST_EMPLOYEE, '2026-11-09', null),
        ])->assertOk()->assertJsonPath('version', 4);
        $this->assertDatabaseMissing('shifts', ['employee_id' => self::FIRST_EMPLOYEE, 'work_date' => '2026-11-09']);
        $this->assertDatabaseHas('shifts', ['employee_id' => self::SECOND_EMPLOYEE, 'work_date' => '2026-11-10']);
    }

    public function test_a_valid_noop_keeps_the_version_but_removing_template_provenance_is_a_real_change(): void
    {
        $change = $this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->morning());
        $this->patchCells(1, [$change])->assertOk()->assertJsonPath('version', 2);
        $this->patchCells(2, [$change])->assertOk()->assertJsonPath('version', 2)->assertJsonCount(1, 'changedCells');
        $this->patchCells(2, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->custom('07:00', '15:00'))])
            ->assertOk()->assertJsonPath('version', 3)->assertJsonPath('changedCells.0.shift.sourceTemplateId', null);
    }

    public function test_two_clients_with_the_same_version_cannot_overwrite_each_other_even_for_a_noop_or_another_week(): void
    {
        $first = $this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->morning());
        $this->patchCells(1, [$first])->assertOk()->assertJsonPath('version', 2);
        $this->assertError($this->patchCells(1, [$first]), 409, 'SCHEDULE_VERSION_CONFLICT')
            ->assertJsonPath('error.details.expectedVersion', 1)->assertJsonPath('error.details.currentVersion', 2);
        $this->assertError($this->patchCells(1, [$this->change(self::SECOND_EMPLOYEE, '2026-12-01', $this->morning())]), 409, 'SCHEDULE_VERSION_CONFLICT');
        $this->assertDatabaseCount('shifts', 1);
        $this->assertSame(2, Schedule::findOrFail(self::SCHEDULE)->version);
    }

    public function test_one_invalid_employee_rolls_back_the_entire_changeset_and_version(): void
    {
        $outsider = Employee::factory()->create();
        $this->assertError($this->patchCells(1, [
            $this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->morning()),
            $this->change($outsider->id, '2026-11-09', $this->morning()),
        ]), 422, 'EMPLOYEE_NOT_IN_SCHEDULE');
        $this->assertDatabaseCount('shifts', 0);
        $this->assertSame(1, Schedule::findOrFail(self::SCHEDULE)->version);
    }

    public function test_an_overlap_rolls_back_every_cell_and_allows_touching_night_boundaries(): void
    {
        $this->assertError($this->patchCells(1, [
            $this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->custom('22:00', '06:00', 1)),
            $this->change(self::FIRST_EMPLOYEE, '2026-11-10', $this->custom('05:00', '09:00')),
            $this->change(self::SECOND_EMPLOYEE, '2026-11-10', $this->morning()),
        ]), 409, 'SHIFT_OVERLAP');
        $this->assertDatabaseCount('shifts', 0);
        $this->assertSame(1, Schedule::findOrFail(self::SCHEDULE)->version);
        $this->patchCells(1, [
            $this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->custom('22:00', '06:00', 1)),
            $this->change(self::FIRST_EMPLOYEE, '2026-11-10', $this->custom('06:00', '14:00')),
        ])->assertOk()->assertJsonPath('version', 2);
    }

    public function test_overlap_detection_includes_an_unrequested_neighbouring_date_and_another_schedule(): void
    {
        $other = Schedule::factory()->create();
        $other->employees()->attach(self::FIRST_EMPLOYEE, ['sort_order' => 0, 'is_active' => true]);
        Shift::factory()->create([
            'schedule_id' => $other->id, 'employee_id' => self::FIRST_EMPLOYEE, 'work_date' => '2026-11-08',
            'starts_at' => '2026-11-08T19:00:00+00:00', 'ends_at' => '2026-11-09T03:00:00+00:00',
            'source_template_id' => null,
        ]);
        $this->assertError($this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->custom('05:00', '09:00'))]), 409, 'SHIFT_OVERLAP');
        $this->assertDatabaseCount('shifts', 1);
        $this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->custom('06:00', '14:00'))])->assertOk();
    }

    #[DataProvider('inactiveScopes')]
    public function test_inactive_employees_or_memberships_cannot_receive_new_assignments_but_can_be_cleared(string $scope): void
    {
        $this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->morning())])->assertOk();
        if ($scope === 'employee') {
            DB::table('employees')->where('id', self::FIRST_EMPLOYEE)->update(['is_active' => false]);
        } else {
            DB::table('schedule_employees')->where('schedule_id', self::SCHEDULE)->where('employee_id', self::FIRST_EMPLOYEE)->update(['is_active' => false]);
        }
        $this->calendar()->assertOk()->assertJsonPath('employees.0.isActive', false)->assertJsonCount(1, 'shifts');
        $this->assertError($this->patchCells(2, [$this->change(self::FIRST_EMPLOYEE, '2026-11-10', $this->morning())]), 422, 'EMPLOYEE_INACTIVE');
        $this->patchCells(2, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', null)])->assertOk()->assertJsonPath('version', 3);
        $this->assertDatabaseCount('shifts', 0);
    }

    public static function inactiveScopes(): array
    {
        return [['employee'], ['membership']];
    }

    public function test_a_template_must_be_active_belong_to_the_schedule_and_match_its_times(): void
    {
        $mismatch = [...$this->morning(), 'startTime' => '08:00'];
        $this->assertError($this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $mismatch)]), 422, 'TEMPLATE_VALUES_MISMATCH');
        DB::table('shift_templates')->where('id', self::MORNING)->update(['is_active' => false]);
        $this->calendar()->assertOk()->assertJsonCount(2, 'shiftTemplates');
        $this->assertError($this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->morning())]), 422, 'TEMPLATE_INACTIVE');
        $otherTemplate = ShiftTemplate::factory()->create();
        $this->assertError($this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', [...$this->morning(), 'sourceTemplateId' => $otherTemplate->id])]), 422, 'TEMPLATE_NOT_FOUND');
    }

    #[DataProvider('invalidShiftTimes')]
    public function test_invalid_shift_intervals_are_rejected_without_writing(string $start, string $end, int $offset): void
    {
        $this->assertError($this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->custom($start, $end, $offset))]), 422, 'INVALID_SHIFT_TIME');
        $this->assertDatabaseCount('shifts', 0);
        $this->assertSame(1, Schedule::findOrFail(self::SCHEDULE)->version);
    }

    public static function invalidShiftTimes(): array
    {
        return [['09:00', '09:00', 0], ['09:00', '09:00', 1], ['17:00', '09:00', 0], ['09:00', '17:00', 1]];
    }

    public function test_missing_version_unknown_fields_and_seconds_are_rejected(): void
    {
        $change = $this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->morning());
        $this->assertError($this->patchJson($this->url('cells'), ['changes' => [$change]]), 422, 'VALIDATION_FAILED');
        foreach (['1', 0, 1.5, true, null] as $version) {
            $this->assertError($this->patchJson($this->url('cells'), ['expectedVersion' => $version, 'changes' => [$change]]), 422, 'VALIDATION_FAILED');
        }
        $this->assertError($this->patchJson($this->url('cells'), ['expectedVersion' => 1, 'changes' => [$change], 'durationMinutes' => 1]), 422, 'VALIDATION_FAILED');
        $withUnknownShiftField = [...$this->morning(), 'durationMinutes' => 1];
        $this->assertError($this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $withUnknownShiftField)]), 422, 'VALIDATION_FAILED');
        $this->assertError($this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->custom('09:00:30', '17:00'))]), 422, 'VALIDATION_FAILED');
        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_empty_duplicate_and_oversized_changesets_are_rejected(): void
    {
        $this->assertError($this->patchCells(1, []), 422, 'EMPTY_CHANGESET');
        $change = $this->change(self::FIRST_EMPLOYEE, '2026-11-09', null);
        $this->assertError($this->patchCells(1, [$change, $change]), 422, 'DUPLICATE_CELL_CHANGE');
        $changes = [];
        $start = new \DateTimeImmutable('2020-01-01');
        for ($day = 0; $day < 2001; $day++) {
            $changes[] = $this->change(self::FIRST_EMPLOYEE, $start->modify("+{$day} days")->format('Y-m-d'), null);
        }
        $this->assertError($this->patchCells(1, $changes), 422, 'VALIDATION_FAILED');
        $this->assertDatabaseCount('shifts', 0);
    }

    #[DataProvider('invalidWorkDates')]
    public function test_invalid_work_dates_reject_assignments_and_clears_atomically(string $workDate, bool $clear): void
    {
        $this->assertError($this->patchCells(1, [
            $this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->morning()),
            $this->change(self::SECOND_EMPLOYEE, $workDate, $clear ? null : $this->morning()),
        ]), 422, 'VALIDATION_FAILED')->assertJsonPath('error.details.field', 'changes.1.workDate');

        $this->assertDatabaseCount('shifts', 0);
        $this->assertSame(1, Schedule::findOrFail(self::SCHEDULE)->version);
    }

    public static function invalidWorkDates(): array
    {
        return [
            'assign with year zero' => ['0000-01-01', false],
            'clear with year zero' => ['0000-01-01', true],
            'assign on nonexistent date' => ['2026-02-30', false],
            'clear on nonexistent date' => ['2026-02-30', true],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_ranges_are_rejected_by_calendar_and_publication(string $from, string $to): void
    {
        $this->assertError($this->calendar($from, $to), 422, 'INVALID_DATE_RANGE');
        $this->assertError($this->postJson($this->url('publications'), ['expectedVersion' => 1, 'from' => $from, 'to' => $to]), 422, 'INVALID_DATE_RANGE');
        $this->assertDatabaseCount('schedule_publications', 0);
    }

    public static function invalidRanges(): array
    {
        return [['2026-11-09', '2026-11-09'], ['2026-11-10', '2026-11-09'], ['2026-02-30', '2026-03-01'], ['2026-01-01', '2026-03-05']];
    }

    public function test_invalid_json_and_other_schedule_ids_return_the_documented_error_envelope(): void
    {
        $this->assertError($this->call('PATCH', $this->url('cells'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{bad json'), 400, 'INVALID_JSON');
        $other = Schedule::factory()->create();
        $this->assertError($this->getJson('/api/v1/schedules/'.$other->id.'/calendar?from=2026-11-09&to=2026-11-16'), 404, 'SCHEDULE_NOT_FOUND');
        $this->assertError($this->getJson('/api/v1/schedules/not-a-uuid/calendar?from=2026-11-09&to=2026-11-16'), 404, 'SCHEDULE_NOT_FOUND');
    }

    public function test_a_publication_is_an_immutable_snapshot_and_retries_remain_idempotent_after_later_saves(): void
    {
        $this->patchCells(1, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->morning())])->assertOk();
        $request = ['expectedVersion' => 2, 'from' => '2026-11-09', 'to' => '2026-11-16'];
        $publication = $this->postJson($this->url('publications'), $request)->assertCreated()->assertJsonPath('sourceVersion', 2);
        $id = $publication->json('id');
        $snapshot = $this->getJson($this->url('publications/'.$id))->assertOk()->assertJsonPath('snapshot.schemaVersion', 1)
            ->assertJsonPath('snapshot.schedule.version', 2)
            ->assertJsonPath('snapshot.range.from', $request['from'])->assertJsonPath('snapshot.range.to', $request['to'])
            ->assertJsonPath('snapshot.shifts.0.label', 'Утро')->assertJsonMissingPath('snapshot.shiftTemplates')->json('snapshot');
        $this->assertSame(2, Schedule::findOrFail(self::SCHEDULE)->version);
        $this->patchCells(2, [$this->change(self::FIRST_EMPLOYEE, '2026-11-09', $this->custom('10:00', '23:00'))])->assertOk();
        DB::table('employees')->where('id', self::FIRST_EMPLOYEE)->update(['full_name' => 'Новое имя']);
        $this->getJson($this->url('publications/'.$id))->assertOk()->assertJsonPath('snapshot', $snapshot);
        $this->postJson($this->url('publications'), $request)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('sourceVersion', 2);
        $this->assertDatabaseCount('schedule_publications', 1);
        $this->assertSame(3, Schedule::findOrFail(self::SCHEDULE)->version);
        $this->assertError($this->postJson($this->url('publications'), [...$request, 'to' => '2026-11-17']), 409, 'SCHEDULE_VERSION_CONFLICT');
        $this->assertDatabaseCount('schedule_publications', 1);
    }

    public function test_empty_publications_are_allowed_and_unknown_publications_do_not_disclose_other_data(): void
    {
        $response = $this->postJson($this->url('publications'), ['expectedVersion' => 1, 'from' => '2026-11-09', 'to' => '2026-11-16'])
            ->assertCreated()->assertJsonPath('sourceVersion', 1);
        $this->getJson($this->url('publications/'.$response->json('id')))->assertOk()->assertJsonCount(0, 'snapshot.shifts')->assertJsonCount(7, 'snapshot.coverage');
        $this->assertError($this->getJson($this->url('publications/'.Str::uuid())), 404, 'PUBLICATION_NOT_FOUND');
        $this->assertSame(1, Schedule::findOrFail(self::SCHEDULE)->version);
    }

    public function test_publication_requires_an_integer_version_and_rejects_unknown_write_fields(): void
    {
        $range = ['from' => '2026-11-09', 'to' => '2026-11-16'];
        $this->assertError($this->postJson($this->url('publications'), $range), 422, 'VALIDATION_FAILED');
        $this->assertError($this->postJson($this->url('publications'), [...$range, 'expectedVersion' => '1']), 422, 'VALIDATION_FAILED');
        $this->assertError($this->postJson($this->url('publications'), [...$range, 'expectedVersion' => 1, 'createdBy' => 'untrusted-client']), 422, 'VALIDATION_FAILED');
        $this->assertDatabaseCount('schedule_publications', 0);
    }

    private function url(string $suffix): string
    {
        return '/api/v1/schedules/'.self::SCHEDULE.'/'.$suffix;
    }

    private function calendar(string $from = '2026-11-09', string $to = '2026-11-16'): TestResponse
    {
        return $this->getJson($this->url('calendar').'?'.http_build_query(['from' => $from, 'to' => $to]));
    }

    /** @param array<int, array<string, mixed>> $changes */
    private function patchCells(int $version, array $changes): TestResponse
    {
        return $this->patchJson($this->url('cells'), ['expectedVersion' => $version, 'changes' => $changes]);
    }

    /** @param array<string, mixed>|null $shift
     * @return array<string, mixed>
     */
    private function change(string $employeeId, string $date, ?array $shift): array
    {
        return ['employeeId' => $employeeId, 'workDate' => $date, 'shift' => $shift];
    }

    /** @return array<string, mixed> */
    private function custom(string $start, string $end, int $offset = 0): array
    {
        return ['startTime' => $start, 'endTime' => $end, 'endDayOffset' => $offset, 'sourceTemplateId' => null];
    }

    /** @return array<string, mixed> */
    private function morning(): array
    {
        return [...$this->custom('07:00', '15:00'), 'sourceTemplateId' => self::MORNING];
    }

    private function assertError(TestResponse $response, int $status, string $code): TestResponse
    {
        return $response->assertStatus($status)->assertJsonPath('error.code', $code)
            ->assertJsonStructure(['error' => ['code', 'message', 'details']])->assertJsonMissingPath('exception')->assertJsonMissingPath('trace');
    }
}
