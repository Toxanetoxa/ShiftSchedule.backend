<?php

namespace App\Models;

use Database\Factories\ScheduleEmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['schedule_id', 'employee_id', 'sort_order', 'is_active'])]
class ScheduleEmployee extends Pivot
{
    /** @use HasFactory<ScheduleEmployeeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'schedule_employees';

    protected $foreignKey = 'schedule_id';

    protected $relatedKey = 'employee_id';

    protected $attributes = ['sort_order' => 0, 'is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Schedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
