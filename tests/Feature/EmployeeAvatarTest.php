<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\GenerateEmployeeAvatar;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EmployeeSeeder;
use DOMDocument;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeAvatarTest extends TestCase
{
    use DatabaseMigrations;

    private const SCHEDULE = '00000000-0000-4000-8000-000000000001';

    private const FIRST_EMPLOYEE = '00000000-0000-4000-8000-000000000201';

    private const SECOND_EMPLOYEE = '00000000-0000-4000-8000-000000000202';

    private const THIRD_EMPLOYEE = '00000000-0000-4000-8000-000000000203';

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('shiftschedule_testing', config('database.connections.pgsql.database'), 'Tests may migrate only the dedicated PostgreSQL testing database.');
        $this->assertSame('shiftschedule_testing', DB::selectOne('SELECT current_database() AS name')->name);
    }

    /** @param array{avatar_url?: string|null} $avatarAttributes */
    #[DataProvider('missingAvatars')]
    public function test_creating_an_employee_persists_an_svg_when_no_avatar_is_provided(array $avatarAttributes): void
    {
        $attributes = Employee::factory()->raw();
        unset($attributes['avatar_url']);

        $employee = Employee::query()->create([...$attributes, ...$avatarAttributes]);

        $this->assertTrue(Str::isUuid($employee->id));
        $this->assertSvgAvatar($employee->avatar_url);
        $this->assertSame($employee->avatar_url, $employee->fresh()->avatar_url);
    }

    /** @return array<string, array{array{avatar_url?: string|null}}> */
    public static function missingAvatars(): array
    {
        return [
            'absent' => [[]],
            'null' => [['avatar_url' => null]],
            'empty' => [['avatar_url' => '']],
            'whitespace' => [['avatar_url' => " \t "]],
        ];
    }

    public function test_avatars_are_deterministic_for_the_employee_id_and_differ_between_employees(): void
    {
        $generator = app(GenerateEmployeeAvatar::class);
        $first = Employee::factory()->create(['id' => self::FIRST_EMPLOYEE]);
        $second = Employee::factory()->create(['id' => self::SECOND_EMPLOYEE]);

        $this->assertSame($generator->generate(self::FIRST_EMPLOYEE), $first->avatar_url);
        $this->assertSame($generator->generate(self::SECOND_EMPLOYEE), $second->avatar_url);
        $this->assertNotSame($first->avatar_url, $second->avatar_url);
    }

    public function test_a_custom_avatar_is_preserved_when_creating_an_employee(): void
    {
        $avatar = 'https://example.com/employee-avatar.png';
        $employee = Employee::factory()->create(['avatar_url' => $avatar]);

        $this->assertSame($avatar, $employee->fresh()->avatar_url);
    }

    public function test_renaming_an_employee_does_not_regenerate_the_avatar(): void
    {
        $employee = Employee::factory()->create();
        $avatar = $employee->avatar_url;

        $employee->update(['full_name' => 'Новое имя сотрудника']);

        $this->assertSame($avatar, $employee->fresh()->avatar_url);
    }

    public function test_initial_seeding_generates_avatars_even_with_model_events_disabled(): void
    {
        $this->seed(DatabaseSeeder::class);

        $employees = Employee::query()->orderBy('id')->get();
        $this->assertCount(count(EmployeeSeeder::EMPLOYEES), $employees);
        foreach ($employees as $employee) {
            $this->assertSvgAvatar($employee->avatar_url);
        }
    }

    public function test_reseeding_fills_only_missing_demo_avatars_and_preserves_other_employee_data(): void
    {
        $missing = Employee::factory()->createQuietly([
            'id' => self::FIRST_EMPLOYEE, 'avatar_url' => null,
            'full_name' => 'Сохранённое имя', 'is_active' => false,
        ]);
        $empty = Employee::factory()->createQuietly(['id' => self::SECOND_EMPLOYEE, 'avatar_url' => '']);
        $custom = Employee::factory()->createQuietly([
            'id' => self::THIRD_EMPLOYEE, 'avatar_url' => 'https://example.com/custom.png',
        ]);
        $outsider = Employee::factory()->createQuietly(['avatar_url' => null]);
        $customBefore = $custom->fresh()->getAttributes();
        $outsiderBefore = $outsider->fresh()->getAttributes();

        $this->seed(DatabaseSeeder::class);

        $this->assertSvgAvatar($missing->fresh()->avatar_url);
        $this->assertSvgAvatar($empty->fresh()->avatar_url);
        $this->assertSame('Сохранённое имя', $missing->fresh()->full_name);
        $this->assertFalse($missing->fresh()->is_active);
        $this->assertSame($customBefore, $custom->fresh()->getAttributes());
        $this->assertSame($outsiderBefore, $outsider->fresh()->getAttributes());
        $beforeReseeding = Employee::query()->orderBy('id')->get()->map->getAttributes()->all();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($beforeReseeding, Employee::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_calendar_api_exposes_the_persisted_employee_avatars(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['schedule.demo_mode' => true, 'schedule.demo_schedule_id' => self::SCHEDULE]);

        $response = $this->getJson(route('schedules.calendar', [
            'scheduleId' => self::SCHEDULE, 'from' => '2026-11-09', 'to' => '2026-11-16',
        ]))->assertOk()->assertJsonCount(8, 'employees');

        foreach ($response->json('employees') as $employee) {
            $this->assertSame(Employee::query()->findOrFail($employee['id'])->avatar_url, $employee['avatarUrl']);
            $this->assertSvgAvatar($employee['avatarUrl']);
        }
    }

    private function assertSvgAvatar(string $avatar): void
    {
        $this->assertStringStartsWith('data:image/svg+xml;', $avatar);
        [$metadata, $encoded] = explode(',', $avatar, 2);
        $svg = str_ends_with($metadata, ';base64') ? base64_decode($encoded, true) : rawurldecode($encoded);
        $this->assertIsString($svg);
        $document = new DOMDocument;

        $this->assertTrue($document->loadXML($svg, LIBXML_NONET));
        $this->assertSame('svg', $document->documentElement->localName);
        $this->assertSame('http://www.w3.org/2000/svg', $document->documentElement->namespaceURI);
        $this->assertSame('128', $document->documentElement->getAttribute('width'));
        $this->assertSame('128', $document->documentElement->getAttribute('height'));
    }
}
