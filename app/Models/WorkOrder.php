<?php

namespace App\Models;

use App\Enums\WorkOrderType;
use App\Enums\WorkUnit;
use App\Support\Format;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id', 'work_item_id', 'worker_id', 'assignment_type',
    'assigned_quantity', 'unit', 'unit_price', 'start_date', 'end_date', 'status', 'notes',
])]
class WorkOrder extends Model
{
    protected function casts(): array
    {
        return [
            'assignment_type' => WorkOrderType::class,
            'unit' => WorkUnit::class,
            'assigned_quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
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

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function label(): string
    {
        $who = $this->worker?->displayName() ?? 'Onbekend';

        return match ($this->assignment_type) {
            WorkOrderType::Project => $who.' · gehele project',
            WorkOrderType::WorkItem => $who.' · '.$this->workItem?->name,
            WorkOrderType::Partial => $who.' · '.Format::qty($this->assigned_quantity).' '.($this->unit?->label() ?? '').' '.$this->workItem?->name,
        };
    }
}
