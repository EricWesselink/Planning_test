<?php

namespace App\Models;

use App\Enums\WorkUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id', 'work_item_id', 'project_area_id', 'worker_id', 'date',
    'completed_quantity', 'unit', 'worked_hours', 'note', 'created_by',
])]
class WorkProgressEntry extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'unit' => WorkUnit::class,
            'completed_quantity' => 'decimal:2',
            'worked_hours' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(ProjectArea::class, 'project_area_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
