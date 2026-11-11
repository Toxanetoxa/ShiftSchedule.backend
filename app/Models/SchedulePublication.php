<?php

namespace App\Models;

use Database\Factories\SchedulePublicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'schedule_id', 'from_date', 'to_date', 'source_version', 'snapshot_schema_version', 'snapshot', 'created_by'])]
class SchedulePublication extends Model
{
    /** @use HasFactory<SchedulePublicationFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $attributes = ['snapshot_schema_version' => 1, 'created_by' => 'demo-manager'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_date' => 'immutable_date:Y-m-d', 'to_date' => 'immutable_date:Y-m-d',
            'source_version' => 'integer', 'snapshot_schema_version' => 'integer',
            'snapshot' => 'array', 'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Schedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
