<?php

namespace App\Services;

use App\Enums\AreaStatus;
use App\Enums\SnagPhotoType;
use App\Enums\WorkPhase;
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

class ProjectBoardService
{
    public function __construct(private RoomWorkSetup $setup) {}

    public function payload(Project $project): array
    {
        $this->setup->ensureProject($project);
        $project->load([
            'customer',
            'floors.areas.tasks.workItem',
            'floors.areas.tasks.completedByWorker',
            'floors.areas.markers',
            'areas.tasks.workItem',
            'areas.tasks.completedByWorker',
            'areas.markers',
            'areas.floor',
            'documents',
            'progressEntries.worker',
            'progressEntries.workItem',
            'progressEntries.area',
            'snags.area',
            'snags.assignee',
            'snags.photos',
        ]);

        $drawing = $project->plattegrond();

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
                'name' => $drawing->original_filename,
                'pdf' => $drawing->isPdf(),
                'image' => $drawing->isImage(),
            ] : null,
            'areas' => $this->areaSummaries($project->areas, $drawing),
            'snags' => $project->snags->map(fn (SnagItem $snag) => $this->snagSummary($snag))->values()->all(),
            'next_snag_number' => (int) $project->snags->max('number') + 1,
            'production' => $this->production($project),
        ];
    }

    public function areaDetail(ProjectArea $area): array
    {
        $this->setup->ensureArea($area);
        $area->loadMissing(['project.documents', 'tasks.workItem', 'tasks.completedByWorker', 'floor', 'markers']);

        $counts = $area->progressCounts();

        return [
            'area' => $this->areaSummary($area, $area->project->plattegrond()),
            'groups' => $area->groupedTasks()->map(function (array $group) {
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
                $displayColor = MaterialColor::resolve(
                    $primary?->workItem?->display_color,
                    $group['label'] ?? $primary?->workItem?->name,
                );
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

    private function markerHasPosition(?AreaDrawingMarker $marker): bool
    {
        return $marker?->hasPosition() ?? false;
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

    public function snagSummary(SnagItem $snag): array
    {
        $snag->loadMissing(['area', 'assignee', 'photos']);

        return [
            'id' => $snag->id,
            'number' => $snag->number,
            'title' => $snag->title(),
            'description' => $snag->description,
            'page' => $snag->drawing_page,
            'x' => $snag->x,
            'y' => $snag->y,
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
