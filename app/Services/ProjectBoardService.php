<?php

namespace App\Services;

use App\Enums\AreaStatus;
use App\Enums\SnagPhotoType;
use App\Enums\WorkPhase;
use App\Enums\WorkUnit;
use App\Models\AreaDrawingMarker;
use App\Models\AreaTask;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\SnagItem;
use App\Support\Format;
use App\Support\MaterialColor;
use App\Support\RoomUniqueName;
use App\Support\WorkColor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ProjectBoardService
{
    public function __construct(private RoomWorkSetup $setup) {}

    public function payload(Project $project): array
    {
        $this->setup->ensureProject($project);
        $project->load([
            'customer',
            'areas.tasks.workItem',
            'areas.tasks.completedByWorker',
            'areas.markers',
            'areas.floor',
            'documents.uploader',
            'workItems',
            'progressEntries.worker',
            'progressEntries.workItem',
            'progressEntries.area',
            'snags.area',
            'snags.assignee',
            'snags.photos',
        ]);

        $drawing = $project->plattegrond();
        foreach ($project->areas as $area) {
            $area->setRelation('project', $project);
        }
        $this->rememberCompletedQuantities($project);
        $areas = $this->areaSummaries($project->areas, $drawing);

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'number' => $project->project_number,
                'status' => $project->status->label(),
            ],
            'drawing' => $drawing ? [
                'id' => $drawing->id,
                'url' => route('projects.documents.show', [$project, $drawing]),
                'download_url' => $this->drawingDownloadUrl($project, $drawing),
                'name' => $drawing->original_filename,
                'pdf' => $drawing->isPdf(),
                'image' => $drawing->isImage(),
            ] : null,
            'areas' => $areas,
            'work_filters' => $this->workFilters($areas),
            'snags' => $project->snags->map(fn (SnagItem $snag) => $this->snagSummary($snag, $drawing))->values()->all(),
            'next_snag_number' => (int) $project->snags->max('number') + 1,
            'production' => $this->production($project),
        ];
    }

    private function rememberCompletedQuantities(Project $project): void
    {
        if (! $project->relationLoaded('progressEntries') || ! $project->relationLoaded('areas')) {
            return;
        }

        $totals = [];
        foreach ($project->progressEntries as $entry) {
            $key = ((int) $entry->project_area_id).':'.((int) $entry->work_item_id);
            $totals[$key] = ($totals[$key] ?? 0.0) + (float) $entry->completed_quantity;
        }

        foreach ($project->areas as $area) {
            if (! $area->relationLoaded('tasks')) {
                continue;
            }
            foreach ($area->tasks as $task) {
                $key = ((int) $task->project_area_id).':'.((int) $task->work_item_id);
                $task->useLoadedCompletedQuantity($totals[$key] ?? 0.0);
            }
        }
    }

    private function drawingDownloadUrl(Project $project, ProjectDocument $drawing): ?string
    {
        $path = (string) $drawing->file_path;
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return route('projects.drawings.download', $project);
    }

    public function areaDetail(ProjectArea $area): array
    {
        $this->setup->ensureArea($area);
        $area->loadMissing(['project.documents', 'project.workItems', 'tasks.workItem', 'tasks.completedByWorker', 'floor', 'markers']);

        $counts = $area->progressCounts();

        return [
            'area' => $this->areaSummary($area, $area->project->plattegrond()),
            'groups' => $area->groupedTasks()->map(function (array $group) use ($area) {
                $tasks = collect($group['tasks']);
                $open = $tasks->filter(fn (AreaTask $task) => ! $task->isDone());
                $done = $tasks->isNotEmpty() && $open->isEmpty();
                $partial = ! $done && $tasks->contains(fn (AreaTask $task) => $task->isDone() || $task->status === AreaStatus::InUitvoering);
                $primary = $open->first() ?? $tasks->first();
                $typeLabel = $tasks
                    ->map(fn (AreaTask $task) => $task->workItem?->typeLabel())
                    ->filter()
                    ->unique()
                    ->values()
                    ->first();
                $colorKey = WorkColor::key($group['key'], $typeLabel, $group['label']);
                $displayColor = $this->groupDisplayColor($area, $group, $primary);
                $ordered = (float) $tasks->sum(fn (AreaTask $task) => (float) $task->ordered_quantity);
                $remaining = (float) $open->sum(fn (AreaTask $task) => $task->remainingQuantity());
                $completed = max(0, round($ordered - $remaining, 2));
                $shown = $open->isEmpty() ? $ordered : $remaining;
                $progressLabel = $primary
                    ? 'Opdracht '.Format::qty($ordered, 2)
                        .' | Gereed '.Format::qty($completed, 2)
                        .' | Rest '.Format::qty($remaining, 2)
                        .' '.$primary->unit->label()
                    : '';

                $provisional = $done && $tasks->contains(fn (AreaTask $task) => $task->isProvisional());

                return [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'type_label' => $typeLabel,
                    'color_key' => $colorKey,
                    'color_label' => WorkColor::legendLabel($colorKey),
                    'display_color' => $displayColor,
                    'display_color_soft' => MaterialColor::softBackground($displayColor),
                    'done' => $done,
                    'partial' => $partial,
                    'provisional' => $provisional,
                    'status_label' => $done ? ($provisional ? 'Voorlopig' : 'Gereed') : ($partial ? 'Bezig' : 'Open'),
                    'task_ids' => $tasks->pluck('id')->values()->all(),
                    'open_task_ids' => $open->pluck('id')->values()->all(),
                    'done_task_ids' => $tasks->filter(fn (AreaTask $task) => $task->isDone())->pluck('id')->values()->all(),
                    'ordered' => round($ordered, 2),
                    'completed' => $completed,
                    'remaining' => round($remaining, 2),
                    'unit' => $primary?->unit->value,
                    'progress_label' => $progressLabel,
                    'quantity_label' => $progressLabel !== '' ? $progressLabel : ($primary ? Format::qty($shown, 2).' '.$primary->unit->label() : ''),
                    'tasks' => $tasks->map(fn (AreaTask $task) => $this->taskPayload($task))->all(),
                ];
            })->all(),
            'progress_label' => $counts['done'].'/'.$counts['total'].' · '.$area->status->label(),
        ];
    }

    /**
     * @param  iterable<int, ProjectArea>  $areas
     * @return list<array<string, mixed>>
     */
    public function areaSummaries(iterable $areas, ?ProjectDocument $drawing): array
    {
        return RoomUniqueName::assign(
            collect($areas)
                ->map(fn (ProjectArea $area) => $this->areaSummaryRow($area, $drawing))
                ->values()
                ->all()
        );
    }

    public function areaSummary(ProjectArea $area, ?ProjectDocument $drawing): array
    {
        $row = $this->areaSummaryRow($area, $drawing);
        $row['unique_name'] = $this->uniqueNameFor($area);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function areaSummaryRow(ProjectArea $area, ?ProjectDocument $drawing): array
    {
        $counts = $area->progressCounts();
        $marker = $drawing
            ? $area->markers->firstWhere('project_document_id', $drawing->id)
            : $area->markers->first();
        $materialColor = $area->materialColor();
        $name = $area->displayName();

        return [
            'id' => $area->id,
            'number' => $area->displayNumber(),
            'name' => $name,
            'unique_name' => $name,
            'number_raw' => $area->area_number,
            'name_raw' => $area->name,
            'label' => $area->label(),
            'm2' => $area->square_meters !== null ? (float) $area->square_meters : null,
            'm2_label' => $area->squareMetersLabel(),
            'floor_id' => $area->project_floor_id,
            'floor' => $area->floor?->name,
            'floor_sort' => $area->floor?->sort_order ?? 999,
            'status' => $area->status->value,
            'status_label' => $area->status->label(),
            'tone' => $area->status->tone(),
            'material_color' => $materialColor,
            'material_color_soft' => MaterialColor::softBackground($materialColor),
            'has_position' => $this->markerHasPosition($marker),
            'link_review' => $this->areaNeedsLinkReview($area, $drawing),
            'page' => $marker?->page,
            'x' => $this->markerHasPosition($marker) ? (float) $marker->x : null,
            'y' => $this->markerHasPosition($marker) ? (float) $marker->y : null,
            'jump_target' => $marker?->jumpTarget(),
            'done' => $counts['done'],
            'total' => $counts['total'],
            'progress' => $counts['total'] > 0 ? $counts['done'].'/'.$counts['total'] : '0/0',
            'phases' => [
                'egaliseren' => $area->showsEgaliseren() ? $area->groupIsDone('ondergrond') : null,
                'vloer' => $area->showsVloer() ? $area->groupIsDone('vloer') : null,
                'plinten' => $area->showsPlinten() ? $area->groupIsDone('plinten') : null,
            ],
            'works' => $this->areaWorks($area),
            'dots' => $this->statusDots($area),
            'marker' => $marker?->toBoardArray(),
        ];
    }

    private function uniqueNameFor(ProjectArea $area): string
    {
        $area->loadMissing(['project.areas.floor', 'project.areas.markers']);
        $rows = $area->project->areas
            ->map(fn (ProjectArea $sibling) => [
                'id' => $sibling->id,
                'name' => $sibling->displayName(),
                'number' => $sibling->displayNumber(),
                'floor' => $sibling->floor?->name,
                'floor_sort' => $sibling->floor?->sort_order ?? 999,
                'page' => $sibling->markers->first()?->page,
                'x' => $sibling->markers->first()?->hasPosition() ? (float) $sibling->markers->first()->x : null,
                'y' => $sibling->markers->first()?->hasPosition() ? (float) $sibling->markers->first()->y : null,
            ])
            ->values()
            ->all();

        foreach (RoomUniqueName::assign($rows) as $row) {
            if ((int) $row['id'] === (int) $area->id) {
                return (string) $row['unique_name'];
            }
        }

        return $area->displayName();
    }

    private function areaNeedsLinkReview(ProjectArea $area, ?ProjectDocument $drawing): bool
    {
        if ($drawing === null) {
            return false;
        }
        $onCurrent = $area->markers->firstWhere('project_document_id', $drawing->id);
        if ($this->markerHasPosition($onCurrent)) {
            return false;
        }

        return $area->markers->contains(
            fn (AreaDrawingMarker $marker): bool => (int) $marker->project_document_id !== (int) $drawing->id
                && $marker->hasPosition()
        );
    }

    private function markerHasPosition(?AreaDrawingMarker $marker): bool
    {
        return $marker?->hasPosition() ?? false;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return list<array{key: string, label: string, color_key: string}>
     */
    private function workFilters(array $areas): array
    {
        $seen = [];
        foreach ($areas as $area) {
            foreach ($area['works'] ?? [] as $work) {
                $key = (string) ($work['key'] ?? '');
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = [
                    'key' => $key,
                    'label' => (string) ($work['label'] ?? $key),
                    'color_key' => (string) ($work['color_key'] ?? 'overige'),
                ];
            }
        }

        $filters = array_values($seen);
        usort($filters, fn (array $left, array $right): int => [$this->workFilterRank($left['key']), $left['label']]
            <=> [$this->workFilterRank($right['key']), $right['label']]);

        return $filters;
    }

    /**
     * @param  array{key?: string, label?: string}  $group
     */
    private function groupDisplayColor(ProjectArea $area, array $group, ?AreaTask $primary): string
    {
        $item = $primary?->workItem;
        $name = $group['label'] ?? $item?->name;
        $groupKey = $primary?->phase()->group();
        $stored = $item?->display_color;
        if ($groupKey === 'plinten') {
            return MaterialColor::forWork(
                $area->plintLegendColor() ?? $stored,
                $name,
            );
        }
        if ($groupKey === 'vloer' && $this->hexMatchesPlintLegend($area, $stored)) {
            $stored = null;
        }

        return MaterialColor::forWork($stored, $name);
    }

    private function hexMatchesPlintLegend(ProjectArea $area, ?string $hex): bool
    {
        $normalized = MaterialColor::normalizeHex($hex);
        if ($normalized === null) {
            return false;
        }
        foreach ($area->plintDisplayColors() as $plintHex) {
            if (MaterialColor::hexesMatch($normalized, $plintHex, 8)) {
                return true;
            }
        }

        return false;
    }

    private function workFilterRank(string $key): int
    {
        if ($key === 'ondergrond' || str_starts_with($key, 'ondergrond')) {
            return 0;
        }
        if (str_starts_with($key, 'vloer')) {
            return 1;
        }
        if (str_starts_with($key, 'plinten')) {
            return 2;
        }

        return 3;
    }

    /**
     * @return list<array{key: string, label: string, color_key: string, done: bool, quantity: float, remaining: float, completed: float, unit: string}>
     */
    private function areaWorks(ProjectArea $area): array
    {
        return $area->groupedTasks()
            ->map(function (array $group) {
                $tasks = collect($group['tasks']);
                if ($tasks->isEmpty()) {
                    return null;
                }
                $typeLabel = $tasks
                    ->map(fn (AreaTask $task) => $task->workItem?->typeLabel())
                    ->filter()
                    ->unique()
                    ->first();
                $measured = $this->workGroupMeasure($tasks);

                return [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'color_key' => WorkColor::key($group['key'], $typeLabel, $group['label']),
                    'done' => $tasks->every(fn (AreaTask $task) => $task->isDone()),
                    'quantity' => $measured['quantity'],
                    'remaining' => $measured['remaining'],
                    'completed' => $measured['completed'],
                    'unit' => $measured['unit'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AreaTask>  $tasks
     * @return array{quantity: float, remaining: float, completed: float, unit: string}
     */
    private function workGroupMeasure(Collection $tasks): array
    {
        $square = $tasks->filter(fn (AreaTask $task) => $task->unit === WorkUnit::SquareMeter);
        $measured = $square->isNotEmpty() ? $square : $tasks;
        $unit = $measured->first()?->unit ?? WorkUnit::SquareMeter;
        $quantity = round((float) $measured->sum(fn (AreaTask $task) => (float) $task->ordered_quantity), 2);
        $remaining = round((float) $measured->sum(fn (AreaTask $task) => $task->remainingQuantity()), 2);

        return [
            'quantity' => $quantity,
            'remaining' => $remaining,
            'completed' => max(0, round($quantity - $remaining, 2)),
            'unit' => $unit->value,
        ];
    }

    /** @return list<array{key: string, label: string, provisional: bool}> */
    private function statusDots(ProjectArea $area): array
    {
        return $area->groupedTasks()
            ->map(function (array $group) {
                $tasks = collect($group['tasks']);
                if ($tasks->isEmpty() || $tasks->contains(fn (AreaTask $task) => ! $task->isDone())) {
                    return null;
                }

                $typeLabel = $tasks
                    ->map(fn (AreaTask $task) => $task->workItem?->typeLabel())
                    ->filter()
                    ->unique()
                    ->first();

                return [
                    'key' => WorkColor::key($group['key'], $typeLabel, $group['label']),
                    'label' => $group['label'],
                    'provisional' => $tasks->contains(fn (AreaTask $task) => $task->isProvisional()),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function taskPayload(AreaTask $task): array
    {
        $ordered = (float) $task->ordered_quantity;
        $completed = $task->completedQuantity();
        $remaining = max(0, round($ordered - $completed, 2));
        $progressLabel = 'Opdracht '.Format::qty($ordered, 2)
            .' | Gereed '.Format::qty($completed, 2)
            .' | Rest '.Format::qty($remaining, 2)
            .' '.$task->unit->label();

        return [
            'id' => $task->id,
            'name' => $this->taskName($task),
            'phase' => $task->phase()->value,
            'quantity' => $ordered,
            'completed' => $completed,
            'remaining' => $remaining,
            'quantity_label' => Format::qty($task->isDone() ? $ordered : $remaining, 2).' '.$task->unit->label(),
            'progress_label' => $progressLabel,
            'quantity_source' => $task->quantity_source,
            'unit' => $task->unit->value,
            'done' => $task->isDone(),
            'provisional' => $task->isProvisional(),
            'worker' => $task->completedByWorker?->planName(),
            'date' => $task->completed_at?->format('d-m-Y'),
        ];
    }

    private function taskName(AreaTask $task): string
    {
        if ($task->workItem) {
            return $task->workItem->cardLabel();
        }

        if ($task->phase() === WorkPhase::Egaliseren || $task->phase() === WorkPhase::Primeren || $task->phase() === WorkPhase::Voorbereiden) {
            return RoomWorkSetup::PRIMEN_EGALISEREN;
        }

        return $task->phase()->label();
    }

    public function snagSummary(SnagItem $snag, ?ProjectDocument $drawing = null): array
    {
        $snag->loadMissing(['area', 'assignee', 'photos']);
        $hidePin = $drawing !== null
            && $snag->link_status === 'review'
            && (int) $snag->document_id !== (int) $drawing->id;

        return [
            'id' => $snag->id,
            'number' => $snag->number,
            'title' => $snag->title(),
            'description' => $snag->description,
            'page' => $snag->drawing_page,
            'x' => $hidePin ? null : $snag->x,
            'y' => $hidePin ? null : $snag->y,
            'link_review' => $snag->link_status === 'review',
            'status' => $snag->status->value,
            'status_label' => $snag->status->boardLabel(),
            'tone' => $snag->status->tone(),
            'area_id' => $snag->project_area_id,
            'area' => $snag->area?->label(),
            'assigned_worker_id' => $snag->assigned_worker_id,
            'worker' => $snag->assignee?->displayName(),
            'priority' => $snag->priority->value,
            'due' => $snag->due_date?->format('d-m-Y'),
            'due_date' => $snag->due_date?->toDateString(),
            'logged_on' => $snag->logged_on?->toDateString() ?: $snag->created_at?->toDateString(),
            'thumb' => $this->snagThumbUrl($snag),
            'photos' => $snag->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'type' => $photo->photo_type->value,
                'type_label' => $photo->photo_type->label(),
                'url' => route('projects.snags.photo', [$snag->project_id, $snag, $photo]),
            ])->values()->all(),
        ];
    }

    private function snagThumbUrl(SnagItem $snag): ?string
    {
        $photo = $snag->photos->firstWhere('photo_type', SnagPhotoType::Issue) ?? $snag->photos->first();

        return $photo
            ? route('projects.snags.photo', [$snag->project_id, $snag, $photo])
            : null;
    }

    private function production(Project $project): array
    {
        return $project->productionByWorker()->map(function (array $row) {
            return [
                'name' => $row['worker']->displayName(),
                'lines' => collect($row['lines'])->map(fn (array $line) => [
                    'work' => $line['work'],
                    'quantity' => Format::qty($line['quantity'], 2),
                    'unit' => $line['unit']?->label(),
                ])->all(),
                'hours' => Format::qty($row['total_hours'] ?? 0, 1),
            ];
        })->all();
    }
}
