<?php

namespace App\Models;

use App\Enums\AreaStatus;
use App\Enums\WorkPhase;
use App\Enums\WorkUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'project_area_id', 'work_item_id', 'ordered_quantity', 'quantity_source', 'perimeter', 'seams', 'unit',
    'status', 'completed_by', 'completed_at', 'approved_at', 'approved_by',
])]
class AreaTask extends Model
{
    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:2',
            'perimeter' => 'decimal:2',
            'seams' => 'decimal:2',
            'unit' => WorkUnit::class,
            'status' => AreaStatus::class,
            'completed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(ProjectArea::class, 'project_area_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function completedByWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'completed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function phase(): WorkPhase
    {
        $this->loadMissing('workItem');

        if ($this->workItem) {
            return $this->workItem->phase();
        }

        return $this->unit === WorkUnit::LinearMeter
            ? WorkPhase::Plinten
            : WorkPhase::Vloer;
    }

    public function isDone(): bool
    {
        return $this->status === AreaStatus::Gereed;
    }

    public function isApproved(): bool
    {
        return $this->isDone() && $this->approved_at !== null;
    }

    public function isProvisional(): bool
    {
        return $this->isDone() && $this->approved_at === null;
    }

    public function completedQuantity(): float
    {
        return round((float) WorkProgressEntry::query()
            ->where('project_area_id', $this->project_area_id)
            ->where('work_item_id', $this->work_item_id)
            ->sum('completed_quantity'), 2);
    }

    public function remainingQuantity(): float
    {
        return max(0, round((float) $this->ordered_quantity - $this->completedQuantity(), 2));
    }

    public function markDone(
        Worker $worker,
        Carbon|string $date,
        ?User $user = null,
        ?float $quantity = null,
        float $hours = 0,
        ?string $note = null,
    ): WorkProgressEntry {
        return DB::transaction(function () use ($worker, $date, $user, $quantity, $hours, $note) {
            $task = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            $task->loadMissing(['area', 'workItem']);

            if ($task->status === AreaStatus::Gereed) {
                throw new \RuntimeException('Deze werkzaamheid is al klaar.');
            }

            $remaining = $task->remainingQuantity();
            if ($remaining <= 0) {
                throw new \RuntimeException('Deze werkzaamheid is al klaar.');
            }

            $amount = $quantity === null ? $remaining : min(max(0, $quantity), $remaining);
            if ($amount <= 0) {
                throw new \RuntimeException('Vul een hoeveelheid groter dan 0 in.');
            }

            $entry = WorkProgressEntry::query()->create([
                'project_id' => $task->area->project_id,
                'work_item_id' => $task->work_item_id,
                'project_area_id' => $task->project_area_id,
                'worker_id' => $worker->id,
                'date' => $date,
                'completed_quantity' => $amount,
                'unit' => $task->unit,
                'worked_hours' => $hours,
                'note' => $note ?: $task->area->label().' · '.$task->workItem->name,
                'created_by' => $user?->id,
            ]);

            $finished = $task->remainingQuantity() <= 0;
            $task->status = $finished ? AreaStatus::Gereed : AreaStatus::InUitvoering;
            $task->completed_by = $worker->id;
            $task->completed_at = $finished ? now() : $task->completed_at;
            if ($finished) {
                if ($user?->progressNeedsApproval()) {
                    $task->approved_at = null;
                    $task->approved_by = null;
                } else {
                    $task->approved_at = now();
                    $task->approved_by = $user?->id;
                }
            }
            $task->save();

            $task->area->refreshStatusFromTasks();
            $task->workItem->syncStatusFromProgress();

            $this->setRawAttributes($task->getAttributes());
            $this->exists = true;
            $this->syncOriginal();

            return $entry;
        });
    }

    public function reopen(): void
    {
        $this->loadMissing(['area', 'workItem']);

        if ($this->status === AreaStatus::NietGestart && $this->completedQuantity() <= 0) {
            return;
        }

        WorkProgressEntry::query()
            ->where('project_area_id', $this->project_area_id)
            ->where('work_item_id', $this->work_item_id)
            ->delete();

        $this->status = AreaStatus::NietGestart;
        $this->completed_by = null;
        $this->completed_at = null;
        $this->approved_at = null;
        $this->approved_by = null;
        $this->save();

        $this->area->refreshStatusFromTasks();
        $this->workItem->syncStatusFromProgress();
    }

    public function approve(?User $user = null): void
    {
        $this->loadMissing('area');

        if (! $this->isDone()) {
            throw new \RuntimeException('Dit werk is nog niet klaar.');
        }

        if ($this->isApproved()) {
            return;
        }

        $this->approved_at = now();
        $this->approved_by = $user?->id;
        $this->save();

        $this->area->refreshStatusFromTasks();
    }
}
