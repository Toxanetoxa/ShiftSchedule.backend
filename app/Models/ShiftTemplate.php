<?php

namespace App\Models;

use Database\Factories\ShiftTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'schedule_id', 'name', 'color_key', 'start_time', 'end_time', 'end_day_offset', 'sort_order', 'is_active'])]
class ShiftTemplate extends Model
{
    /** @use HasFactory<ShiftTemplateFactory> */
    use HasFactory, HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $attributes = ['end_day_offset' => 0, 'sort_order' => 0, 'is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'end_day_offset' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Schedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'source_template_id');
    }
}
