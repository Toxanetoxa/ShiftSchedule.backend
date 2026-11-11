<?php

namespace App\Models;

use App\Services\GenerateEmployeeAvatar;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'full_name', 'position_title', 'avatar_url', 'is_active'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $attributes = ['position_title' => 'Сотрудник', 'is_active' => true];

    protected static function booted(): void
    {
        static::creating(function (Employee $employee): void {
            if (blank($employee->avatar_url)) {
                $employee->avatar_url = app(GenerateEmployeeAvatar::class)->generate($employee->id);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsToMany<Schedule, $this, ScheduleEmployee> */
    public function schedules(): BelongsToMany
    {
        return $this->belongsToMany(Schedule::class, 'schedule_employees')
            ->using(ScheduleEmployee::class)
            ->withPivot(['sort_order', 'is_active']);
    }

    /** @return HasMany<ScheduleEmployee, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ScheduleEmployee::class);
    }

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
