<?php

namespace App\Services;

use App\Enums\AreaStatus;
use App\Enums\ImportDocumentType;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\SnagItem;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\Meetstaat\MaterialIdentity;
use Illuminate\Support\Facades\DB;

class SourceUpdateService
{
    public const CONFIRM_MESSAGE = 'Er bestaat al een versie van dit bestand voor dit project. Wil je de bestaande gegevens bijwerken?';

    public function __construct(
        private SourceDocumentService $documents,
        private MaterialIdentity $identity,
        private CalculationImportService $calculations,
        private RoomWorkSetup $setup,
    ) {}

    public function findProjectByWorkNumber(?string $number): ?Project
    {
        $number = trim((string) $number);
        if ($number === '') {
            return null;
        }

        return Project::query()->where('project_number', $number)->first();
    }

    /**
     * @param  list<string>  $types
     */
    public function hasExistingVersions(Project $project, array $types): bool
    {
        foreach ($types as $type) {
            if ($this->documents->current($project, $type) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  list<string>  $types
     * @return array{
     *     message: string,
     *     has_existing_versions: bool,
     *     types: list<string>,
     *     diffs: array<string, mixed>
     * }
     */
    public function compare(Project $project, array $preview, array $types): array
    {
        $types = array_values(array_unique(array_filter($types)));
        $existing = $this->hasExistingVersions($project, $types);
        $diffs = [];

        if (in_array(ImportDocumentType::Meetstaat->value, $types, true)) {
            $diffs['meetstaat'] = $this->meetstaatDiff($project, $preview);
        }
        if (in_array(ImportDocumentType::Materialenstaat->value, $types, true)) {
            $diffs['materialenstaat'] = $this->materialDiff($project, $preview);
        }
        if (in_array(ImportDocumentType::Calculatie->value, $types, true)) {
            $diffs['calculatie'] = $this->excelDiff($project, $preview);
        }
        if (in_array(ImportDocumentType::Plattegrond->value, $types, true)) {
            $diffs['plattegrond'] = $this->plattegrondDiff($project);
        }

        return [
            'message' => $existing
                ? self::CONFIRM_MESSAGE
                : 'Dit bestand hoort bij project '.$project->displayTitle().' ('.$project->project_number.'). Wil je het toevoegen aan dit project?',
            'has_existing_versions' => $existing,
            'types' => $types,
            'diffs' => $diffs,
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  list<array{path: string, type: string, original: string}>  $files
     * @param  list<string>  $types
     */
    public function apply(Project $project, User $user, array $preview, array $files, array $types): Project
    {
        return DB::transaction(function () use ($project, $user, $preview, $files, $types) {
            [$files, $types] = $this->retargetDrawingUpload($preview, $files, $types);
            $types = array_values(array_unique(array_filter($types)));
            $byType = [];
            foreach ($files as $file) {
                $byType[$file['type']] = $file;
            }

            if (in_array(ImportDocumentType::Meetstaat->value, $types, true)) {
                $this->applyMeetstaat($project, $preview);
                $this->storeTypedFile($project, $user, $byType, ImportDocumentType::Meetstaat->value, 'ok', [
                    'header' => $preview['header'] ?? [],
                    'works' => $preview['works'] ?? [],
                ]);
            }

            if (in_array(ImportDocumentType::Materialenstaat->value, $types, true)) {
                $this->storeTypedFile(
                    $project,
                    $user,
                    $byType,
                    ImportDocumentType::Materialenstaat->value,
                    'ok',
                    [
                        'works' => $this->materialWorks($preview),
                    ],
                );
            }

            if (in_array(ImportDocumentType::Calculatie->value, $types, true)) {
                $this->storeTypedFile($project, $user, $byType, ImportDocumentType::Calculatie->value, 'ok', [
                    'filename' => $preview['calculation']['filename'] ?? null,
                    'work_number' => $preview['calculation']['work_number'] ?? ($preview['header']['project_number'] ?? null),
                    'total_hours' => $preview['calculation']['total_hours'] ?? null,
                    'total_labor_cost' => $preview['calculation']['total_labor_cost'] ?? null,
                ]);
                $this->calculations->replaceFromPreview($project->fresh(['documents', 'workItems']), $preview);
            }

            if (in_array(ImportDocumentType::Plattegrond->value, $types, true)) {
                $previous = $this->documents->current($project, ImportDocumentType::Plattegrond->value);
                $stored = $this->storeTypedFile($project, $user, $byType, ImportDocumentType::Plattegrond->value);
                if ($stored !== null) {
                    $this->markDrawingLinksForReview($project, $previous, $stored);
                }
            }

            foreach ($files as $file) {
                if (in_array($file['type'], $types, true)) {
                    continue;
                }
                if (! in_array($file['type'], SourceDocumentService::sourceTypes(), true)) {
                    continue;
                }
                $this->storeTypedFile($project, $user, [$file['type'] => $file], $file['type']);
            }

            $this->recalculate($project->fresh(['areas.tasks.workItem', 'workItems', 'documents']));

            return $project->fresh(['documents', 'areas.tasks.workItem', 'workItems', 'calculationLines']);
        });
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array{new: list<array<string, mixed>>, changed: list<array<string, mixed>>, unchanged: list<array<string, mixed>>, removed: list<array<string, mixed>>}
     */
    public function meetstaatDiff(Project $project, array $preview): array
    {
        $project->loadMissing(['areas.floor', 'areas.tasks.workItem']);
        $incoming = $this->incomingMeetstaatRows($preview);
        $existing = $this->existingMeetstaatRows($project);
        $used = [];
        $new = [];
        $changed = [];
        $unchanged = [];

        foreach ($incoming as $row) {
            $match = $this->matchExistingRow($existing, $row, $used);
            if ($match === null) {
                $new[] = $row;

                continue;
            }
            $used[] = $match['key'];
            $fields = $this->changedFields($match, $row);
            if ($fields === []) {
                $unchanged[] = $row;

                continue;
            }
            $changed[] = [
                ...$row,
                'changes' => $fields,
            ];
        }

        $removed = [];
        foreach ($existing as $row) {
            if (! in_array($row['key'], $used, true)) {
                $removed[] = $row;
            }
        }

        return compact('new', 'changed', 'unchanged', 'removed');
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array{new: list<array<string, mixed>>, changed: list<array<string, mixed>>, unchanged: list<array<string, mixed>>, removed: list<array<string, mixed>>}
     */
    public function materialDiff(Project $project, array $preview): array
    {
        $incoming = $this->materialWorks($preview);
        $previous = $this->documents->current($project, ImportDocumentType::Materialenstaat->value);
        $existingWorks = is_array($previous?->parsed_json['works'] ?? null)
            ? $previous->parsed_json['works']
            : $project->workItems->map(fn (WorkItem $item): array => [
                'name' => $item->name,
                'declared_total' => (float) $item->ordered_quantity,
                'bruto' => null,
                'group' => null,
            ])->all();

        $existing = [];
        foreach ($existingWorks as $work) {
            if (! is_array($work)) {
                continue;
            }
            $row = $this->materialRow($work);
            $existing[$row['key']] = $row;
        }

        $new = [];
        $changed = [];
        $unchanged = [];
        $seen = [];
        foreach ($incoming as $work) {
            $row = $this->materialRow($work);
            $seen[] = $row['key'];
            $match = $this->findMaterialRow($existing, $row);
            if ($match === null) {
                $new[] = $row;

                continue;
            }
            $fields = [];
            if (! $this->sameNumber($match['netto'] ?? null, $row['netto'] ?? null)) {
                $fields[] = ['field' => 'netto', 'from' => $match['netto'], 'to' => $row['netto']];
            }
            if (! $this->sameNumber($match['bruto'] ?? null, $row['bruto'] ?? null)) {
                $fields[] = ['field' => 'bruto', 'from' => $match['bruto'], 'to' => $row['bruto']];
            }
            if (($match['group'] ?? '') !== ($row['group'] ?? '')) {
                $fields[] = ['field' => 'groep', 'from' => $match['group'], 'to' => $row['group']];
            }
            if ($fields === []) {
                $unchanged[] = $row;

                continue;
            }
            $changed[] = [...$row, 'changes' => $fields];
        }

        $removed = [];
        foreach ($existing as $row) {
            if (! in_array($row['key'], $seen, true) && $this->findMaterialRow(
                array_map(fn (array $work): array => $this->materialRow($work), $incoming),
                $row
            ) === null) {
                $removed[] = $row;
            }
        }

        return compact('new', 'changed', 'unchanged', 'removed');
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array{new: list<array<string, mixed>>, changed: list<array<string, mixed>>, unchanged: list<array<string, mixed>>, removed: list<array<string, mixed>>}
     */
    public function excelDiff(Project $project, array $preview): array
    {
        $project->loadMissing('calculationLines');
        $incoming = [];
        foreach ($preview['calculation']['lines'] ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $incoming[] = $this->excelRow($line);
        }

        $existing = [];
        foreach ($project->calculationLines as $line) {
            $existing[$this->excelKey([
                'row_number' => $line->row_number,
                'article_description' => $line->article_description,
                'production_description' => $line->production_description,
            ])] = [
                'key' => $this->excelKey([
                    'row_number' => $line->row_number,
                    'article_description' => $line->article_description,
                    'production_description' => $line->production_description,
                ]),
                'label' => (string) ($line->article_description ?: $line->production_description ?: 'Regel '.$line->row_number),
                'row_number' => $line->row_number,
                'quantity' => $line->quantity !== null ? (float) $line->quantity : null,
                'hours' => $line->hours !== null ? (float) $line->hours : null,
                'total_cost' => $line->total_cost !== null ? (float) $line->total_cost : null,
            ];
        }

        $new = [];
        $changed = [];
        $unchanged = [];
        $used = [];
        foreach ($incoming as $row) {
            $match = $existing[$row['key']] ?? null;
            if ($match === null) {
                $new[] = $row;

                continue;
            }
            $used[] = $row['key'];
            $fields = [];
            foreach (['quantity', 'hours', 'total_cost'] as $field) {
                if (! $this->sameNumber($match[$field] ?? null, $row[$field] ?? null)) {
                    $fields[] = ['field' => $field, 'from' => $match[$field] ?? null, 'to' => $row[$field] ?? null];
                }
            }
            if ($fields === []) {
                $unchanged[] = $row;

                continue;
            }
            $changed[] = [...$row, 'changes' => $fields];
        }

        $removed = [];
        foreach ($existing as $row) {
            if (! in_array($row['key'], $used, true)) {
                $removed[] = $row;
            }
        }

        return compact('new', 'changed', 'unchanged', 'removed');
    }

    /**
     * @return array{current_revision: int, next_revision: int, filename: ?string}
     */
    public function plattegrondDiff(Project $project): array
    {
        $current = $this->documents->current($project, ImportDocumentType::Plattegrond->value);

        return [
            'current_revision' => (int) ($current?->revision ?? 0),
            'next_revision' => (int) ($current?->revision ?? 0) + 1,
            'filename' => $current?->original_filename,
        ];
    }

    public function typesFromPreview(array $preview): array
    {
        $types = [];
        $sources = is_array($preview['sources'] ?? null) ? $preview['sources'] : [];
        foreach ([
            ImportDocumentType::Meetstaat->value,
            ImportDocumentType::Materialenstaat->value,
            ImportDocumentType::Plattegrond->value,
            ImportDocumentType::Snijmaten->value,
        ] as $type) {
            if (! empty($sources[$type])) {
                $types[] = $type;
            }
        }
        if (! empty($preview['calculation']['lines']) || ! empty($preview['calculation']['labor'])) {
            $types[] = ImportDocumentType::Calculatie->value;
        }

        return array_values(array_unique($types));
    }

    /**
     * Een bouwtekening kan als meetstaat gelabeld zijn terwijl de preview alleen een plattegrond bevat.
     * Die PDF als meetstaat opslaan herschrijft ruimtes en toont de nieuwe tekening niet.
     *
     * @param  array<string, mixed>  $preview
     * @param  list<array{path: string, type: string, original: string}>  $files
     * @param  list<string>  $types
     * @return array{0: list<array{path: string, type: string, original: string}>, 1: list<string>}
     */
    private function retargetDrawingUpload(array $preview, array $files, array $types): array
    {
        $sources = is_array($preview['sources'] ?? null) ? $preview['sources'] : [];
        if (! empty($sources['meetstaat']) || empty($sources['plattegrond'])) {
            return [$files, $types];
        }

        $types = array_values(array_filter(
            $types,
            fn (string $type): bool => $type !== ImportDocumentType::Meetstaat->value,
        ));
        if (! in_array(ImportDocumentType::Plattegrond->value, $types, true)) {
            $types[] = ImportDocumentType::Plattegrond->value;
        }

        foreach ($files as $file) {
            if (($file['type'] ?? '') === ImportDocumentType::Plattegrond->value) {
                return [$files, $types];
            }
        }

        foreach ($files as $index => $file) {
            if (($file['type'] ?? '') === ImportDocumentType::Meetstaat->value) {
                $files[$index]['type'] = ImportDocumentType::Plattegrond->value;

                break;
            }
        }

        return [$files, $types];
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function applyMeetstaat(Project $project, array $preview): void
    {
        $project->load(['areas.floor', 'areas.tasks.workItem', 'workItems', 'floors']);
        $incoming = $this->incomingMeetstaatRows($preview);
        $usedAreaIds = [];
        $workItems = [];
        foreach ($project->workItems as $item) {
            $workItems[$item->name] = $item;
        }
        foreach ($preview['works'] ?? [] as $work) {
            if (! is_array($work) || trim((string) ($work['name'] ?? '')) === '') {
                continue;
            }
            $this->rememberWork($workItems, $this->workItemForName($project, $workItems, (string) $work['name'], $work), $work);
        }

        foreach ($incoming as $row) {
            $area = $this->matchArea($project, $row, $usedAreaIds);
            if ($area === null) {
                $area = $this->createArea($project, $row);
            } else {
                $area->forceFill([
                    'square_meters' => $row['square_meters'],
                    'name' => $row['room_name'] !== '' ? $row['room_name'] : $area->name,
                ])->save();
            }
            $usedAreaIds[] = $area->id;

            $item = $this->workItemForName($project, $workItems, $row['product'], [
                'name' => $row['product'],
                'unit' => $row['unit'],
            ]);
            $this->upsertSourceTask($area, $item, $row);
        }

        $this->syncWorkItemQuantities($project->fresh(['workItems.areaTasks']));
    }

    /**
     * @param  array<string, WorkItem>  $workItems
     * @param  array<string, mixed>  $work
     */
    private function workItemForName(Project $project, array &$workItems, string $name, array $work): WorkItem
    {
        $name = trim($name);
        foreach ($workItems as $candidate) {
            if (strcasecmp($candidate->name, $name) === 0
                || $this->identity->sharesIdentity($candidate->name, $name)) {
                return $candidate;
            }
        }

        $unit = $work['unit'] ?? WorkUnit::SquareMeter->value;
        if ($unit instanceof WorkUnit) {
            $unit = $unit->value;
        }
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name !== '' ? $name : 'Werk',
            'unit' => $unit ?: WorkUnit::SquareMeter->value,
            'ordered_quantity' => 0,
            'planned_start_date' => $project->planned_start_date,
            'planned_end_date' => $project->planned_end_date,
            'status' => 'gepland',
            'sort_order' => (int) $project->workItems()->max('sort_order') + 1,
        ]);
        $workItems[$item->name] = $item;
        $project->unsetRelation('workItems');

        return $item;
    }

    /**
     * @param  array<string, WorkItem>  $workItems
     * @param  array<string, mixed>  $work
     */
    private function rememberWork(array &$workItems, WorkItem $item, array $work): void
    {
        foreach (array_merge([$item->name], is_array($work['source_names'] ?? null) ? $work['source_names'] : []) as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $workItems[$name] = $item;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createArea(Project $project, array $row): ProjectArea
    {
        $floor = ProjectFloor::query()->firstOrCreate(
            ['project_id' => $project->id, 'name' => $row['floor'] !== '' ? $row['floor'] : 'Onbekend'],
            ['sort_order' => (int) $project->floors()->max('sort_order') + 1]
        );

        return ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => $row['room_number'] !== '' ? $row['room_number'] : null,
            'name' => $row['room_name'] !== '' ? $row['room_name'] : ($row['room_number'] !== '' ? $row['room_number'] : 'Ruimte'),
            'square_meters' => $row['square_meters'],
            'status' => AreaStatus::NietGestart,
            'sort_order' => (int) $project->areas()->max('sort_order') + 1,
        ]);
    }

    /**
     * @param  list<int>  $usedAreaIds
     * @param  array<string, mixed>  $row
     */
    private function matchArea(Project $project, array $row, array $usedAreaIds): ?ProjectArea
    {
        $floor = mb_strtolower((string) $row['floor']);
        $number = (string) $row['room_number'];
        $name = mb_strtolower((string) $row['room_name']);

        return $project->areas->first(function (ProjectArea $area) use ($floor, $number, $name, $usedAreaIds) {
            if (in_array($area->id, $usedAreaIds, true)) {
                return false;
            }
            $sameFloor = mb_strtolower((string) $area->floor?->name) === $floor;
            $sameNumber = (string) $area->area_number === $number;
            $sameName = mb_strtolower((string) $area->name) === $name;

            return $sameFloor && ($number !== '' ? $sameNumber : $sameName);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertSourceTask(ProjectArea $area, WorkItem $item, array $row): void
    {
        $existing = AreaTask::query()
            ->where('project_area_id', $area->id)
            ->where('work_item_id', $item->id)
            ->first();

        $values = [
            'ordered_quantity' => $row['quantity'],
            'perimeter' => $row['perimeter'],
            'seams' => $row['seams'],
            'unit' => $row['unit'],
            'quantity_source' => 'meetstaat',
        ];

        if ($existing === null) {
            AreaTask::query()->create([
                ...$values,
                'project_area_id' => $area->id,
                'work_item_id' => $item->id,
                'status' => AreaStatus::NietGestart,
            ]);

            return;
        }

        $existing->forceFill($values)->save();
    }

    private function syncWorkItemQuantities(Project $project): void
    {
        foreach ($project->workItems as $item) {
            if ($item->isExtraWork()) {
                continue;
            }
            $item->forceFill([
                'ordered_quantity' => round((float) $item->areaTasks()->sum('ordered_quantity'), 2),
            ])->save();
        }
    }

    private function recalculate(Project $project): void
    {
        $this->syncWorkItemQuantities($project);
        $this->setup->ensureProject($project);
        $this->calculations->refreshBudgets($project);
    }

    /**
     * @param  array<string, array{path: string, type: string, original: string}>  $byType
     */
    private function storeTypedFile(
        Project $project,
        User $user,
        array $byType,
        string $type,
        string $parseStatus = 'none',
        ?array $parsedJson = null,
    ): ?ProjectDocument {
        $file = $byType[$type] ?? null;
        if ($file === null || ! is_file($file['path'])) {
            return null;
        }

        return $this->documents->storeFromPath(
            $project,
            $file['path'],
            $type,
            $file['original'],
            $user,
            $parseStatus,
            $parsedJson,
        );
    }

    private function markDrawingLinksForReview(Project $project, ?ProjectDocument $previous, ProjectDocument $current): void
    {
        if ($previous === null || (int) $previous->id === (int) $current->id) {
            return;
        }

        SnagItem::query()
            ->where('project_id', $project->id)
            ->where(function ($query) use ($previous): void {
                $query->where('document_id', $previous->id)
                    ->orWhereNull('document_id');
            })
            ->whereNotNull('x')
            ->update(['link_status' => 'review']);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<array<string, mixed>>
     */
    private function incomingMeetstaatRows(array $preview): array
    {
        $rows = [];
        foreach ($preview['areas'] ?? [] as $area) {
            if (! is_array($area)) {
                continue;
            }
            $tasks = $area['tasks'] ?? [];
            if ($tasks === []) {
                $rows[] = $this->meetstaatRow($area, [
                    'work_name' => '',
                    'quantity' => $area['square_meters'] ?? null,
                    'unit' => WorkUnit::SquareMeter->value,
                    'perimeter' => $area['perimeter'] ?? null,
                    'seams' => $area['seams'] ?? null,
                ]);

                continue;
            }
            foreach ($tasks as $task) {
                if (! is_array($task)) {
                    continue;
                }
                $rows[] = $this->meetstaatRow($area, $task);
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function existingMeetstaatRows(Project $project): array
    {
        $rows = [];
        foreach ($project->areas as $area) {
            $tasks = $area->tasks;
            if ($tasks->isEmpty()) {
                $rows[] = $this->meetstaatRowFromArea($area, null);

                continue;
            }
            foreach ($tasks as $task) {
                $rows[] = $this->meetstaatRowFromArea($area, $task);
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $area
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function meetstaatRow(array $area, array $task): array
    {
        $floor = trim((string) ($area['floor'] ?? ''));
        $number = trim((string) ($area['room_number'] ?? $area['area_number'] ?? ''));
        $name = trim((string) ($area['room_name'] ?? $area['name'] ?? ''));
        $product = trim((string) ($task['work_name'] ?? ''));
        $unit = $task['unit'] ?? WorkUnit::SquareMeter->value;
        $unitValue = $unit instanceof WorkUnit ? $unit->value : (string) $unit;
        $quantity = ($unitValue === WorkUnit::LinearMeter->value)
            ? $this->floatOrNull($task['perimeter'] ?? $task['quantity'] ?? null)
            : $this->floatOrNull($task['quantity'] ?? $area['square_meters'] ?? null);

        return [
            'key' => $this->meetstaatKey($floor, $number, $name, $product),
            'floor' => $floor,
            'room_number' => $number,
            'room_name' => $name,
            'product' => $product,
            'square_meters' => $this->floatOrNull($area['square_meters'] ?? null),
            'quantity' => $quantity,
            'perimeter' => $this->floatOrNull($task['perimeter'] ?? $area['perimeter'] ?? null),
            'doors' => $this->floatOrNull($task['doors'] ?? $task['omtrek_minus_deuren'] ?? $area['doors'] ?? null),
            'seams' => $this->floatOrNull($task['seams'] ?? $area['seams'] ?? null),
            'unit' => $unitValue,
            'label' => trim($number.' '.$name).($product !== '' ? ' · '.$product : ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meetstaatRowFromArea(ProjectArea $area, ?AreaTask $task): array
    {
        $product = (string) ($task?->workItem?->name ?? '');
        $unit = $task?->unit ?? WorkUnit::SquareMeter;

        return [
            'key' => $this->meetstaatKey((string) $area->floor?->name, (string) $area->area_number, (string) $area->name, $product),
            'floor' => (string) $area->floor?->name,
            'room_number' => (string) $area->area_number,
            'room_name' => (string) $area->name,
            'product' => $product,
            'square_meters' => $area->square_meters !== null ? (float) $area->square_meters : null,
            'quantity' => $task !== null ? (float) $task->ordered_quantity : ($area->square_meters !== null ? (float) $area->square_meters : null),
            'perimeter' => $task?->perimeter !== null ? (float) $task->perimeter : null,
            'doors' => null,
            'seams' => $task?->seams !== null ? (float) $task->seams : null,
            'unit' => $unit instanceof WorkUnit ? $unit->value : (string) $unit,
            'label' => trim($area->area_number.' '.$area->name).($product !== '' ? ' · '.$product : ''),
            'area_id' => $area->id,
        ];
    }

    private function meetstaatKey(string $floor, string $number, string $name, string $product): string
    {
        return mb_strtolower($floor).'|'.mb_strtolower($number).'|'.mb_strtolower($name).'|'.mb_strtolower($product);
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  array<string, mixed>  $row
     * @param  list<string>  $used
     * @return array<string, mixed>|null
     */
    private function matchExistingRow(array $existing, array $row, array $used): ?array
    {
        foreach ($existing as $candidate) {
            if (in_array($candidate['key'], $used, true)) {
                continue;
            }
            $sameFloor = mb_strtolower((string) $candidate['floor']) === mb_strtolower((string) $row['floor']);
            $sameNumber = (string) $candidate['room_number'] === (string) $row['room_number'] && $row['room_number'] !== '';
            $sameName = mb_strtolower((string) $candidate['room_name']) === mb_strtolower((string) $row['room_name']);
            $sameProduct = $this->sameProduct((string) $candidate['product'], (string) $row['product']);
            if ($sameFloor && $sameProduct && ($sameNumber || ($row['room_number'] === '' && $sameName))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    private function changedFields(array $from, array $to): array
    {
        $fields = [];
        foreach (['square_meters' => 'oppervlakte', 'quantity' => 'hoeveelheid', 'perimeter' => 'omtrek', 'doors' => 'omtrek minus deuren', 'seams' => 'naden'] as $key => $label) {
            if (! $this->sameNumber($from[$key] ?? null, $to[$key] ?? null)) {
                $fields[] = ['field' => $label, 'from' => $from[$key] ?? null, 'to' => $to[$key] ?? null];
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<array<string, mixed>>
     */
    private function materialWorks(array $preview): array
    {
        $baselines = is_array($preview['closure_baselines'] ?? null) ? $preview['closure_baselines'] : [];
        $works = $baselines['material_works'] ?? $preview['material_works'] ?? [];
        if (! is_array($works) || $works === []) {
            $works = [];
            foreach ($preview['works'] ?? [] as $work) {
                if (is_array($work) && array_key_exists('material_list_netto', $work)) {
                    $works[] = $work;
                }
            }
        }

        return array_values(array_filter($works, fn ($work): bool => is_array($work)));
    }

    /**
     * @param  array<string, mixed>  $work
     * @return array<string, mixed>
     */
    private function materialRow(array $work): array
    {
        $name = trim((string) ($work['name'] ?? ''));
        $group = trim((string) ($work['group'] ?? $work['floor'] ?? $work['bouwlaag'] ?? ''));
        $netto = $this->floatOrNull($work['declared_total'] ?? $work['material_list_netto'] ?? $work['netto'] ?? null);
        $bruto = $this->floatOrNull($work['bruto'] ?? $work['gross'] ?? $work['declared_bruto'] ?? null);

        return [
            'key' => mb_strtolower($group).'|'.mb_strtolower($name),
            'name' => $name,
            'group' => $group,
            'netto' => $netto,
            'bruto' => $bruto,
            'label' => trim($group !== '' ? $group.' · '.$name : $name),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $existing
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function findMaterialRow(array $existing, array $row): ?array
    {
        if (isset($existing[$row['key']])) {
            return $existing[$row['key']];
        }
        foreach ($existing as $candidate) {
            $sameGroup = mb_strtolower((string) ($candidate['group'] ?? '')) === mb_strtolower((string) ($row['group'] ?? ''));
            if ($sameGroup && $this->sameProduct((string) $candidate['name'], (string) $row['name'])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function excelRow(array $line): array
    {
        $label = trim((string) (($line['article_description'] ?? '') ?: ($line['production_description'] ?? '') ?: 'Regel '.($line['row_number'] ?? '')));

        return [
            'key' => $this->excelKey($line),
            'label' => $label,
            'row_number' => $line['row_number'] ?? null,
            'quantity' => $this->floatOrNull($line['quantity'] ?? null),
            'hours' => $this->floatOrNull($line['hours'] ?? null),
            'total_cost' => $this->floatOrNull($line['total_cost'] ?? $line['labor_cost'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function excelKey(array $line): string
    {
        $label = trim((string) (($line['article_description'] ?? '') ?: ($line['production_description'] ?? '')));

        return (int) ($line['row_number'] ?? 0).'|'.mb_strtolower($label);
    }

    private function sameProduct(string $left, string $right): bool
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === $right) {
            return true;
        }
        if ($left === '' || $right === '') {
            return $left === $right;
        }

        return strcasecmp($left, $right) === 0
            || $this->identity->sharesIdentity($left, $right);
    }

    private function sameNumber(mixed $left, mixed $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }
        if ($left === null || $right === null) {
            return false;
        }

        return abs((float) $left - (float) $right) < 0.005;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }
}
