<?php

namespace App\Services;

use App\Enums\AreaStatus;
use App\Enums\WorkPhase;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\WorkItem;
use App\Support\WorkType;

class RoomWorkSetup
{
    public const PRIMEN_EGALISEREN = 'Primen & Egaliseren';

    public const DERIVED_FLOORING_SOURCE = 'afgeleid van vloerafwerking';

    public function ensureProject(Project $project): void
    {
        $project->loadMissing(['areas.tasks.workItem', 'workItems']);

        foreach ($project->areas as $area) {
            $this->ensureAreaState($area);
        }

        $this->syncProjectPrimingQuantity($project);
    }

    public function ensureArea(ProjectArea $area): void
    {
        $area->loadMissing(['tasks.workItem', 'project.workItems']);
        $this->ensureAreaState($area);
        $this->syncProjectPrimingQuantity($area->project);
    }

    private function ensureAreaState(ProjectArea $area): void
    {
        $area->loadMissing(['tasks.workItem', 'project.workItems']);

        $derivedMeters = $this->primingMetersFromFlooring($area);
        $existing = $this->existingPrimingTask($area);

        if ($existing !== null && $this->hasExplicitPrimingQuantity($existing)) {
            $this->collapseOndergrond($area);
            $this->syncWorkItemName($existing->fresh(['workItem'])->workItem, $this->primingWorkName());
            $area->unsetRelation('tasks');
            $area->load('tasks.workItem');
            $area->refreshStatusFromTasks();

            return;
        }

        if ($derivedMeters <= 0.0001) {
            if ($existing !== null && $this->isDerivedPrimingTask($existing)) {
                $this->removeDuplicateTask($existing);
                $area->unsetRelation('tasks');
                $area->load('tasks.workItem');
                $area->refreshStatusFromTasks();
            }

            return;
        }

        $this->collapseOndergrond($area);
        $area->unsetRelation('tasks');
        $area->load(['tasks.workItem', 'project.workItems']);
        $this->ensurePrimingQuantity($area, $derivedMeters);
        $area->unsetRelation('tasks');
        $area->load('tasks.workItem');
        $area->refreshStatusFromTasks();
    }

    /**
     * Netto m² primen/egaliseren afgeleid van harde vloerafwerkingen die egalisatie vereisen.
     * Deeloppervlakken (verschillende werkitems) worden opgeteld; geen plinten.
     */
    public function primingMetersFromFlooring(ProjectArea $area): float
    {
        $area->loadMissing('tasks.workItem');

        $byWorkItem = [];
        foreach ($area->tasks as $task) {
            if (! $this->isFlooringSquareMeterTask($task)) {
                continue;
            }
            $name = (string) ($task->workItem?->name ?? '');
            if (! WorkType::requiresPrimingLeveling($name)) {
                continue;
            }
            $workItemId = (int) $task->work_item_id;
            $byWorkItem[$workItemId] = ($byWorkItem[$workItemId] ?? 0.0) + (float) $task->ordered_quantity;
        }

        return round(array_sum($byWorkItem), 2);
    }

    /**
     * Projecttotaal primen/egaliseren: harde vloer-werkitems (PVC, linoleum, vinyl),
     * ook wanneer een deel van die m² nog geen ruimtetaken heeft.
     */
    public function primingMetersFromWorkItems(Project $project): float
    {
        $project->loadMissing('workItems');

        $total = 0.0;
        foreach ($project->workItems as $item) {
            if ($item->phase()->group() === 'ondergrond') {
                continue;
            }
            if ($item->unit === WorkUnit::LinearMeter) {
                continue;
            }
            if (! WorkType::requiresPrimingLeveling((string) $item->name)) {
                continue;
            }
            $total += (float) $item->ordered_quantity;
        }

        return round($total, 2);
    }

    private function syncProjectPrimingQuantity(Project $project): void
    {
        $project->unsetRelation('workItems');
        $project->unsetRelation('areas');
        $project->load(['workItems', 'areas.tasks.workItem']);

        $fromWorkItems = $this->primingMetersFromWorkItems($project);
        $fromAreas = round((float) $project->areas
            ->flatMap(fn (ProjectArea $area) => $area->tasks)
            ->filter(fn (AreaTask $task) => $this->isPrimenEgaliserenTask($task))
            ->sum(fn (AreaTask $task) => (float) $task->ordered_quantity), 2);
        $target = round(max($fromWorkItems, $fromAreas), 2);
        $name = $this->primingWorkName();
        $item = $project->workItems->first(fn (WorkItem $work) => $work->name === $name)
            ?? $project->workItems->first(fn (WorkItem $work) => $work->phase() === WorkPhase::Egaliseren && ! $this->isOldPrimerName($work->name));

        if ($target <= 0.0001) {
            return;
        }

        if ($item === null) {
            $item = $this->createWorkItem($project, $name, WorkPhase::Egaliseren, 1);
        } else {
            $this->syncWorkItemName($item, $name);
        }

        if (abs((float) $item->ordered_quantity - $target) <= 0.0001) {
            return;
        }

        $item->ordered_quantity = $target;
        $item->save();
    }

    private function primingWorkName(): string
    {
        $configured = config('flooring.priming_leveling.work_name');

        return is_string($configured) && $configured !== '' ? $configured : self::PRIMEN_EGALISEREN;
    }

    private function derivedSourceLabel(): string
    {
        $configured = config('flooring.priming_leveling.source_label');

        return is_string($configured) && $configured !== '' ? $configured : self::DERIVED_FLOORING_SOURCE;
    }

    private function existingPrimingTask(ProjectArea $area): ?AreaTask
    {
        return $area->tasks->first(fn (AreaTask $task) => $this->isPrimenEgaliserenTask($task))
            ?? $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);
    }

    private function hasExplicitPrimingQuantity(AreaTask $task): bool
    {
        if ((float) $task->ordered_quantity <= 0.0001) {
            return false;
        }

        return ! $this->isDerivedPrimingTask($task);
    }

    private function isDerivedPrimingTask(AreaTask $task): bool
    {
        return (string) ($task->quantity_source ?? '') === $this->derivedSourceLabel();
    }

    private function isFlooringSquareMeterTask(AreaTask $task): bool
    {
        if ($task->phase() !== WorkPhase::Vloer) {
            return false;
        }

        $unit = $task->unit;
        if ($unit === WorkUnit::LinearMeter || $unit === WorkUnit::LinearMeter->value) {
            return false;
        }

        return true;
    }

    private function ensurePrimingQuantity(ProjectArea $area, float $quantity): void
    {
        $name = $this->primingWorkName();
        $source = $this->derivedSourceLabel();
        $existing = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren)
            ?? $area->tasks->first(fn (AreaTask $task) => $this->isPrimenEgaliserenTask($task));

        if ($existing) {
            $this->syncWorkItemName($existing->workItem, $name);
            $this->setTaskQuantity($existing, $quantity, $source);

            return;
        }

        $project = $area->project;
        $item = $project->workItems->first(fn (WorkItem $work) => $work->name === $name)
            ?? $project->workItems->first(fn (WorkItem $work) => $work->phase() === WorkPhase::Egaliseren && ! $this->isOldPrimerName($work->name))
            ?? $this->createWorkItem($project, $name, WorkPhase::Egaliseren, 1);

        $this->syncWorkItemName($item, $name);

        $item->ordered_quantity = round((float) $item->ordered_quantity + $quantity, 2);
        $item->save();

        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $item->id,
            'ordered_quantity' => $quantity,
            'quantity_source' => $source,
            'unit' => WorkUnit::SquareMeter,
            'status' => AreaStatus::NietGestart,
        ]);

        $project->unsetRelation('workItems');
        $project->load('workItems');
        $area->unsetRelation('tasks');
        $area->load('tasks.workItem');
    }

    private function setTaskQuantity(AreaTask $task, float $quantity, string $source): void
    {
        $previous = (float) $task->ordered_quantity;
        $delta = round($quantity - $previous, 2);
        $task->ordered_quantity = $quantity;
        $task->quantity_source = $source;
        $task->save();

        $item = $task->workItem;
        if ($item && abs($delta) > 0.0001) {
            $item->ordered_quantity = max(0, round((float) $item->ordered_quantity + $delta, 2));
            $item->save();
        }
    }

    private function collapseOndergrond(ProjectArea $area): void
    {
        $area->unsetRelation('tasks');
        $area->load('tasks.workItem');

        $ondergrond = $area->tasks
            ->filter(fn (AreaTask $task) => $task->phase()->group() === 'ondergrond')
            ->values();

        if ($ondergrond->isEmpty()) {
            return;
        }

        $keep = $ondergrond->first(fn (AreaTask $task) => $this->isPrimenEgaliserenTask($task))
            ?? $ondergrond->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren)
            ?? $ondergrond->first();

        foreach ($ondergrond as $task) {
            if ((int) $task->id === (int) $keep->id) {
                continue;
            }

            $this->removeDuplicateTask($task);
        }

        $keep->unsetRelation('workItem');
        $keep->load('workItem');
        $this->syncWorkItemName($keep->workItem, $this->primingWorkName());

        $area->unsetRelation('tasks');
        $area->load(['tasks.workItem', 'project.workItems']);
    }

    private function isPrimenEgaliserenTask(AreaTask $task): bool
    {
        $name = mb_strtolower((string) $task->workItem?->name);

        return (str_contains($name, 'egal') || str_contains($name, 'primen') || str_contains($name, 'primer'))
            && ! preg_match('/voorbereid|schuur/', $name);
    }

    private function removeDuplicateTask(AreaTask $task): void
    {
        $item = $task->workItem;
        $qty = (float) $task->ordered_quantity;
        $task->delete();

        if ($item) {
            $this->subtractQuantity($item, $qty);
        }
    }

    private function subtractQuantity(WorkItem $item, float $qty): void
    {
        $item->ordered_quantity = max(0, round((float) $item->ordered_quantity - $qty, 2));
        $item->save();

        if ($item->areaTasks()->doesntExist() && $item->progressEntries()->doesntExist()) {
            $item->delete();
            $item->project?->unsetRelation('workItems');
            $item->project?->load('workItems');
        }
    }

    private function isOldPrimerName(?string $name): bool
    {
        $name = mb_strtolower((string) $name);

        return str_contains($name, 'primer') && ! str_contains($name, 'egal');
    }

    private function syncWorkItemName(?WorkItem $item, string $name): void
    {
        if ($item === null || $item->name === $name) {
            return;
        }

        if ($item->phase()->group() === 'ondergrond') {
            $item->name = $name;
            $item->save();
        }
    }

    private function createWorkItem(Project $project, string $name, WorkPhase $phase, int $sort): WorkItem
    {
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 0,
            'planned_start_date' => $project->planned_start_date,
            'planned_end_date' => $project->planned_end_date,
            'status' => 'gepland',
            'sort_order' => $sort,
        ]);
        $project->setRelation('workItems', $project->workItems->push($item));

        return $item;
    }
}
