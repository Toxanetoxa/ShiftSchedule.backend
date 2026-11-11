<?php

namespace App\Models;

use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'name', 'time_zone', 'version'])]
class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use HasFactory, HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $attributes = ['time_zone' => 'Europe/Moscow', 'version' => 1];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsToMany<Employee, $this, ScheduleEmployee> */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'schedule_employees')
            ->using(ScheduleEmployee::class)
            ->withPivot(['sort_order', 'is_active']);
    }

    /** @return HasMany<ScheduleEmployee, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ScheduleEmployee::class);
    }

    /** @return HasMany<ShiftTemplate, $this> */
    public function shiftTemplates(): HasMany
    {
        return $this->hasMany(ShiftTemplate::class);
    }

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /** @return HasMany<SchedulePublication, $this> */
    public function publications(): HasMany
    {
        return $this->hasMany(SchedulePublication::class);
    }
}
