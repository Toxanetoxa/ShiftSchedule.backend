<?php

namespace App\Models;

use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'schedule_id', 'employee_id', 'work_date', 'starts_at', 'ends_at', 'source_template_id', 'label_snapshot', 'color_key_snapshot'])]
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory, HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date:Y-m-d', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
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

    /** @return BelongsTo<ShiftTemplate, $this> */
    public function sourceTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'source_template_id');
    }
}
