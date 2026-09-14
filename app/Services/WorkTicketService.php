<?php

namespace App\Services;

use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkTicket;
use App\Services\Meetstaat\FloorLabel;
use App\Support\Format;
use App\Support\PlanningHours;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkTicketService
{
    public function __construct(
        private VoucherPriceResolver $prices,
        private FloorLabel $floors,
    ) {}

    /**
     * @return array{
     *     assignment: WorkerAssignment,
     *     project: Project,
     *     worker: Worker,
     *     kind: WorkTicketKind,
     *     isExternal: bool,
     *     floors: list<array<string, mixed>>,
     *     workItems: list<array<string, mixed>>,
     *     documents: list<array<string, mixed>>,
     *     quantities: array<int, array<int, array{quantity: float, unit: string}>>,
     *     hourlyRate: float|null,
     *     existing: Collection<int, WorkTicket>
     * }
     */
    public function draft(WorkerAssignment $assignment): array
    {
        $assignment->loadMissing([
            'worker.rates',
            'project.customer',
            'project.floors.areas.tasks.workItem',
            'project.areas.tasks.workItem',
            'project.workItems',
            'project.workOrders',
            'project.documents',
            'workTickets',
            'workItem',
        ]);

        $project = $assignment->project;
        $worker = $assignment->worker;
        if ($project === null || $worker === null) {
            throw ValidationException::withMessages([
                'assignment' => 'Deze inzet heeft geen project of vakman.',
            ]);
        }

        $kind = WorkTicketKind::forWorker($worker);
        $quantities = $this->quantityMap($project);

        return [
            'assignment' => $assignment,
            'project' => $project,
            'worker' => $worker,
            'kind' => $kind,
            'isExternal' => $kind === WorkTicketKind::Opdrachtbon,
            'floors' => $this->floorOptions($project),
            'workItems' => $this->workItemOptions($project, $worker, $assignment),
            'documents' => $this->documentOptions($project),
            'quantities' => $quantities,
            'hourlyRate' => $worker->hourlyRate() !== null ? (float) $worker->hourlyRate()->unit_price : null,
            'existing' => $assignment->workTickets,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function boardMode(WorkerAssignment $assignment): array
    {
        $draft = $this->draft($assignment);
        $project = $draft['project'];
        $worker = $draft['worker'];
        $kind = $draft['kind'];

        return [
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'save_label' => $kind->label().' opslaan',
            'assignment_id' => (int) $assignment->id,
            'store_url' => route('work-tickets.store', $assignment),
            'planning_url' => route('planning', ['project_id' => $project->id]),
            'worker_name' => $worker->planName(),
            'worker_company' => $worker->company,
            'project_name' => $project->displayTitle(),
            'customer_name' => $project->customer?->name,
            'is_external' => $draft['isExternal'],
            'hourly_rate' => $draft['hourlyRate'],
            'document_id' => $project->plattegrond()?->id,
            'work_items' => $draft['workItems'],
            'extra_works' => $this->extraWorkOptions($project),
            'existing' => $draft['existing']
                ->map(fn (WorkTicket $ticket): array => [
                    'id' => (int) $ticket->id,
                    'number' => $ticket->number,
                    'label' => $ticket->kind->label().' '.$ticket->number,
                    'url' => route('work-tickets.show', $ticket),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function store(WorkerAssignment $assignment, array $input, User $user): WorkTicket
    {
        $assignment->loadMissing([
            'worker.rates',
            'project.floors.areas.tasks.workItem',
            'project.areas.tasks.workItem',
            'project.workItems',
            'project.documents',
            'project.workOrders',
            'project.customer',
        ]);

        $project = $assignment->project;
        $worker = $assignment->worker;
        if ($project === null || $worker === null) {
            throw ValidationException::withMessages([
                'assignment' => 'Deze inzet heeft geen project of vakman.',
            ]);
        }

        $selection = $this->resolveSelection($project, $input, $assignment);
        $kind = WorkTicketKind::forWorker($worker);
        $billing = $kind === WorkTicketKind::Opdrachtbon
            ? WorkTicketBilling::from((string) $input['billing_method'])
            : null;

        $lines = $this->buildLines(
            $project,
            $worker,
            $selection['area_ids'],
            $selection['work_item_ids'],
            $billing,
            $input,
            $selection['totals'] ?? null,
        );
        if ($lines === []) {
            throw ValidationException::withMessages([
                $this->hasGeneralWork($input) ? 'extra_work_item_ids' : ($this->hasSelections($input) ? 'selections' : 'work_item_ids') => $this->hasGeneralWork($input)
                    ? 'Vink algemeen werk aan of kies ruimtes en werkzaamheden.'
                    : 'Geen Meetstaat-hoeveelheid voor de geselecteerde ruimtes en werkzaamheden.',
            ]);
        }

        return DB::transaction(function () use ($assignment, $project, $worker, $user, $kind, $billing, $selection, $lines, $input): WorkTicket {
            $ticket = WorkTicket::query()->create([
                'number' => WorkTicket::nextNumber($kind),
                'kind' => $kind,
                'worker_assignment_id' => $assignment->id,
                'project_id' => $project->id,
                'worker_id' => $worker->id,
                'team_id' => $assignment->team_id,
                'created_by' => $user->id,
                'billing_method' => $billing,
                'hourly_rate' => $billing === WorkTicketBilling::Hourly
                    ? round((float) Format::decimalInput($input['hourly_rate'] ?? null), 2)
                    : null,
                'fixed_price' => $billing === WorkTicketBilling::Fixed
                    ? round((float) Format::decimalInput($input['fixed_price'] ?? null), 2)
                    : null,
                'notes' => filled($input['notes'] ?? null) ? trim((string) $input['notes']) : null,
                'start_date' => $assignment->start_date->toDateString(),
                'end_date' => $assignment->end_date->toDateString(),
            ]);

            foreach ($selection['floors'] as $floorId => $entire) {
                $ticket->floors()->attach($floorId, ['entire_floor' => $entire]);
            }
            $ticket->areas()->attach($selection['area_ids']);
            if ($selection['document_ids'] !== []) {
                $ticket->documents()->attach($selection['document_ids']);
            }
            foreach ($lines as $line) {
                $ticket->lines()->create($line);
            }

            return $ticket->fresh([
                'worker',
                'project.customer',
                'lines.workItem',
                'areas.floor',
                'floors',
                'documents',
                'assignment.crewMembers',
            ]);
        });
    }

    /**
     * @return list<array{work_item_id: int, quantity: float, unit: WorkUnit, unit_price: float|null, amount: float|null}>
     */
    public function buildLines(
        Project $project,
        Worker $worker,
        array $areaIds,
        array $workItemIds,
        ?WorkTicketBilling $billing,
        array $input = [],
        ?array $totals = null,
    ): array {
        $totals ??= $this->totalsFor($project, $areaIds, $workItemIds);
        $orders = $project->relationLoaded('workOrders') ? $project->workOrders : $project->workOrders()->get();
        $postedPrices = is_array($input['unit_prices'] ?? null) ? $input['unit_prices'] : [];
        $lines = [];

        foreach ($workItemIds as $itemId) {
            $row = $totals[$itemId] ?? null;
            if ($row === null || $row['quantity'] <= 0.0001) {
                continue;
            }

            $item = $project->workItems->firstWhere('id', $itemId);
            $unitPrice = null;
            $amount = null;
            if ($billing === WorkTicketBilling::Unit) {
                $posted = Format::decimalInput($postedPrices[$itemId] ?? null);
                if ($posted !== null && $posted !== '') {
                    $unitPrice = round((float) $posted, 2);
                } else {
                    $resolved = $this->prices->resolve($worker, $item, $row['unit'], null, $orders);
                    $unitPrice = $resolved['price'] !== null ? round((float) $resolved['price'], 2) : null;
                }
                if ($unitPrice === null) {
                    throw ValidationException::withMessages([
                        'unit_prices.'.$itemId => 'Vul een prijs in voor '.$row['name'].', of zet eerst een afgesproken prijs bij de vakman.',
                    ]);
                }
                $amount = round($row['quantity'] * $unitPrice, 2);
            }

            $lines[] = [
                'work_item_id' => $itemId,
                'quantity' => $row['quantity'],
                'unit' => $row['unit'],
                'unit_price' => $unitPrice,
                'amount' => $amount,
            ];
        }

        return $lines;
    }

    /**
     * @param  list<int>  $areaIds
     * @param  list<int>  $workItemIds
     * @return array<int, array{name: string, quantity: float, unit: WorkUnit}>
     */
    public function totalsFor(Project $project, array $areaIds, array $workItemIds): array
    {
        if ($areaIds === [] || $workItemIds === []) {
            return [];
        }

        $tasks = AreaTask::query()
            ->with('workItem')
            ->whereIn('project_area_id', $areaIds)
            ->whereIn('work_item_id', $workItemIds)
            ->orderBy('id')
            ->get();

        $totals = [];
        foreach ($tasks as $task) {
            $itemId = (int) $task->work_item_id;
            $qty = round((float) $task->ordered_quantity, 2);
            if ($qty <= 0.0001) {
                continue;
            }
            if (! isset($totals[$itemId])) {
                $totals[$itemId] = [
                    'name' => $task->workItem?->name ?? 'Werkzaamheid',
                    'quantity' => 0.0,
                    'unit' => $task->unit,
                ];
            }
            $totals[$itemId]['quantity'] = round($totals[$itemId]['quantity'] + $qty, 2);
        }

        return $totals;
    }

    /**
     * @return array{
     *     floors: array<int, bool>,
     *     area_ids: list<int>,
     *     work_item_ids: list<int>,
     *     document_ids: list<int>,
     *     totals: array<int, array{name: string, quantity: float, unit: WorkUnit}>|null
     * }
     */
    public function resolveSelection(Project $project, array $input, ?WorkerAssignment $assignment = null): array
    {
        $project->loadMissing(['floors.areas.tasks.workItem', 'areas.tasks.workItem', 'workItems', 'documents']);
        $extraIds = $this->extraWorkItemIds($project, $input, $assignment);

        if ($this->hasSelections($input)) {
            return $this->mergeExtraWork($project, $this->resolveSelections($project, $input), $extraIds, $assignment);
        }

        $floorsInput = is_array($input['floors'] ?? null) ? $input['floors'] : [];
        $hasFloorChoice = false;
        foreach ($floorsInput as $payload) {
            if (is_array($payload) && ! empty($payload['included'])) {
                $hasFloorChoice = true;
                break;
            }
        }

        if (! $hasFloorChoice) {
            if ($extraIds === []) {
                throw ValidationException::withMessages([
                    'extra_work_item_ids' => 'Kies ruimtes, of vink algemeen werk aan.',
                ]);
            }

            return [
                'floors' => [],
                'area_ids' => [],
                'work_item_ids' => $extraIds,
                'document_ids' => $this->idsInProject(
                    is_array($input['document_ids'] ?? null) ? $input['document_ids'] : [],
                    $project->documents->pluck('id')->all(),
                ),
                'totals' => $this->extraWorkTotals($project, $extraIds, $assignment),
            ];
        }
        $selectedFloors = [];
        $areaIds = [];

        foreach ($floorsInput as $floorKey => $payload) {
            if (! is_array($payload) || empty($payload['included'])) {
                continue;
            }

            $floorId = (int) $floorKey;
            $entire = ($payload['scope'] ?? '') === 'entire';

            if ($floorId === 0) {
                $pool = $project->areas->whereNull('project_floor_id');
                $chosen = $this->chosenAreaIds($payload, $pool, true);
                $areaIds = array_merge($areaIds, $chosen);

                continue;
            }

            $floor = $project->floors->firstWhere('id', $floorId);
            if ($floor === null) {
                continue;
            }

            $chosen = $this->chosenAreaIds($payload, $floor->areas, $entire);
            if ($chosen === []) {
                continue;
            }

            $selectedFloors[$floorId] = $entire;
            $areaIds = array_merge($areaIds, $chosen);
        }

        $areaIds = array_values(array_unique($areaIds));
        if ($areaIds === []) {
            throw ValidationException::withMessages([
                'floors' => 'Kies minstens één verdieping of ruimte.',
            ]);
        }

        $workItemIds = $this->idsInProject(
            is_array($input['work_item_ids'] ?? null) ? $input['work_item_ids'] : [],
            $project->workItems->pluck('id')->all(),
        );
        if ($workItemIds === []) {
            throw ValidationException::withMessages([
                'work_item_ids' => 'Kies minstens één werkzaamheid.',
            ]);
        }

        $documentIds = $this->idsInProject(
            is_array($input['document_ids'] ?? null) ? $input['document_ids'] : [],
            $project->documents->pluck('id')->all(),
        );

        return $this->mergeExtraWork($project, [
            'floors' => $selectedFloors,
            'area_ids' => $areaIds,
            'work_item_ids' => $workItemIds,
            'document_ids' => $documentIds,
            'totals' => null,
        ], $extraIds, $assignment);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function hasSelections(array $input): bool
    {
        return is_array($input['selections'] ?? null) && $input['selections'] !== [];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function hasGeneralWork(array $input): bool
    {
        return $this->extraWorkItemIdsFromInput($input) !== []
            || filter_var($input['general_work'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function extraWorkItemIdsFromInput(array $input): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $id): int => (int) $id,
                is_array($input['extra_work_item_ids'] ?? null) ? $input['extra_work_item_ids'] : [],
            ),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function extraWorkItemIds(Project $project, array $input, ?WorkerAssignment $assignment): array
    {
        $extraIds = $project->workItems
            ->filter(fn (WorkItem $item): bool => $item->isExtraWork())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $ids = $this->idsInProject($this->extraWorkItemIdsFromInput($input), $extraIds);
        $generalId = (int) ($assignment?->work_item_id ?? 0);
        if (filter_var($input['general_work'] ?? false, FILTER_VALIDATE_BOOLEAN) && $generalId > 0) {
            $ids[] = $generalId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array{
     *     floors: array<int, bool>,
     *     area_ids: list<int>,
     *     work_item_ids: list<int>,
     *     document_ids: list<int>,
     *     totals: array<int, array{name: string, quantity: float, unit: WorkUnit}>|null
     * }  $resolved
     * @param  list<int>  $extraIds
     * @return array{
     *     floors: array<int, bool>,
     *     area_ids: list<int>,
     *     work_item_ids: list<int>,
     *     document_ids: list<int>,
     *     totals: array<int, array{name: string, quantity: float, unit: WorkUnit}>|null
     * }
     */
    private function mergeExtraWork(Project $project, array $resolved, array $extraIds, ?WorkerAssignment $assignment): array
    {
        if ($extraIds === []) {
            return $resolved;
        }

        $resolved['work_item_ids'] = array_values(array_unique(array_merge($resolved['work_item_ids'], $extraIds)));
        $base = $resolved['totals'];
        if ($base === null && $resolved['area_ids'] !== []) {
            $base = $this->totalsFor($project, $resolved['area_ids'], $resolved['work_item_ids']);
        }

        $resolved['totals'] = ($base ?? []) + $this->extraWorkTotals($project, $extraIds, $assignment);

        return $resolved;
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<int, array{name: string, quantity: float, unit: WorkUnit}>
     */
    private function extraWorkTotals(Project $project, array $itemIds, ?WorkerAssignment $assignment): array
    {
        $totals = [];
        $fallbackHours = max(1.0, $assignment?->plannedHoursValue() ?? 1.0);

        foreach ($itemIds as $itemId) {
            $item = $project->workItems->firstWhere('id', $itemId);
            if ($item === null) {
                continue;
            }

            $totals[$itemId] = $this->extraWorkTotal($item, $fallbackHours);
        }

        return $totals;
    }

    /**
     * @return array{name: string, quantity: float, unit: WorkUnit}
     */
    private function extraWorkTotal(WorkItem $item, float $fallbackHours): array
    {
        $hours = $item->begrote_uren === null ? 0.0 : (float) $item->begrote_uren;

        return [
            'name' => $item->name,
            'quantity' => $hours > 0.0001 ? $hours : $fallbackHours,
            'unit' => WorkUnit::Hours,
        ];
    }

    /**
     * @return list<array{id: int, name: string, qty_label: string}>
     */
    private function extraWorkOptions(Project $project): array
    {
        return $project->workItems
            ->filter(fn (WorkItem $item): bool => $item->isExtraWork())
            ->sortBy('sort_order')
            ->map(function (WorkItem $item): array {
                $total = $this->extraWorkTotal($item, 1.0);

                return [
                    'id' => (int) $item->id,
                    'name' => $item->name,
                    'qty_label' => PlanningHours::hoursLabel($total['quantity']).' · nacalculatie',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     floors: array<int, bool>,
     *     area_ids: list<int>,
     *     work_item_ids: list<int>,
     *     document_ids: list<int>,
     *     totals: array<int, array{name: string, quantity: float, unit: WorkUnit}>
     * }
     */
    private function resolveSelections(Project $project, array $input): array
    {
        $floors = [];
        $areaIds = [];
        $workItemIds = [];
        $totals = [];

        foreach ($input['selections'] as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            $resolved = $this->resolveChunk($project, $chunk);
            if ($resolved === null) {
                continue;
            }

            $chunkTotals = $this->totalsFor($project, $resolved['area_ids'], $resolved['work_item_ids']);
            foreach ($chunkTotals as $itemId => $row) {
                if (! isset($totals[$itemId])) {
                    $totals[$itemId] = $row;
                } else {
                    $totals[$itemId]['quantity'] = round($totals[$itemId]['quantity'] + $row['quantity'], 2);
                }
            }

            foreach ($resolved['floors'] as $floorId => $entire) {
                $floors[$floorId] = ($floors[$floorId] ?? true) && $entire;
            }
            $areaIds = array_merge($areaIds, $resolved['area_ids']);
            $workItemIds = array_merge($workItemIds, $resolved['work_item_ids']);
        }

        $areaIds = array_values(array_unique($areaIds));
        $workItemIds = array_values(array_unique($workItemIds));
        if ($areaIds === [] || $workItemIds === []) {
            throw ValidationException::withMessages([
                'selections' => 'Kies minstens één verdieping, materiaal en ruimte.',
            ]);
        }

        return [
            'floors' => $floors,
            'area_ids' => $areaIds,
            'work_item_ids' => $workItemIds,
            'document_ids' => $this->idsInProject(
                is_array($input['document_ids'] ?? null) ? $input['document_ids'] : [],
                $project->documents->pluck('id')->all(),
            ),
            'totals' => $totals,
        ];
    }

    /**
     * @param  array<string, mixed>  $chunk
     * @return array{floors: array<int, bool>, area_ids: list<int>, work_item_ids: list<int>}|null
     */
    private function resolveChunk(Project $project, array $chunk): ?array
    {
        $floorId = (int) ($chunk['floor_id'] ?? 0);
        $entire = filter_var($chunk['entire'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $keys = array_values(array_filter(
            array_map(
                static fn (mixed $key): string => trim((string) $key),
                is_array($chunk['work_keys'] ?? null) ? $chunk['work_keys'] : [],
            ),
            static fn (string $key): bool => $key !== '',
        ));
        if ($keys === []) {
            return null;
        }

        if ($floorId === 0) {
            $pool = $project->areas->whereNull('project_floor_id');
            $chosen = $this->chosenAreaIds($chunk, $pool, $entire);
            $floors = [];
        } else {
            $floor = $project->floors->firstWhere('id', $floorId);
            if ($floor === null) {
                return null;
            }
            $chosen = $this->chosenAreaIds($chunk, $floor->areas, $entire);
            $floors = $chosen === [] ? [] : [$floorId => $entire];
        }

        if ($chosen === []) {
            return null;
        }

        $workItemIds = $this->workItemIdsForKeys($project, $chosen, $keys);
        if ($workItemIds === []) {
            return null;
        }

        return [
            'floors' => $floors,
            'area_ids' => $chosen,
            'work_item_ids' => $workItemIds,
        ];
    }

    /**
     * @param  list<int>  $areaIds
     * @param  list<string>  $keys
     * @return list<int>
     */
    private function workItemIdsForKeys(Project $project, array $areaIds, array $keys): array
    {
        $wanted = array_flip($keys);
        $ids = [];
        foreach ($project->areas as $area) {
            if (! in_array((int) $area->id, $areaIds, true)) {
                continue;
            }
            foreach ($area->tasks as $task) {
                if (! isset($wanted[$this->taskBoardKey($task)])) {
                    continue;
                }
                $ids[] = (int) $task->work_item_id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function taskBoardKey(AreaTask $task): string
    {
        $group = $task->phase()->group();
        if ($group === 'ondergrond') {
            return 'ondergrond';
        }

        return $group.'|'.((int) $task->work_item_id ?: 'task-'.$task->id);
    }

    /**
     * @return array<int, array<int, array{quantity: float, unit: string}>>
     */
    private function quantityMap(Project $project): array
    {
        $map = [];
        foreach ($project->areas as $area) {
            foreach ($area->tasks as $task) {
                $qty = round((float) $task->ordered_quantity, 2);
                if ($qty <= 0.0001) {
                    continue;
                }
                $map[(int) $area->id][(int) $task->work_item_id] = [
                    'quantity' => $qty,
                    'unit' => $task->unit instanceof WorkUnit ? $task->unit->value : (string) $task->unit,
                ];
            }
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function floorOptions(Project $project): array
    {
        $options = $project->floors
            ->map(fn (ProjectFloor $floor): array => [
                'id' => (int) $floor->id,
                'name' => trim((string) $floor->name) !== '' ? trim((string) $floor->name) : 'Verdieping',
                'areas' => $floor->areas
                    ->sortBy(fn (ProjectArea $area): string => ProjectArea::numberSortKey($area->area_number).$area->name)
                    ->values()
                    ->map(fn (ProjectArea $area): array => [
                        'id' => (int) $area->id,
                        'label' => $area->label(),
                    ])
                    ->all(),
            ])
            ->values()
            ->all();

        $loose = $project->areas
            ->whereNull('project_floor_id')
            ->sortBy(fn (ProjectArea $area): string => ProjectArea::numberSortKey($area->area_number).$area->name)
            ->values();
        if ($loose->isNotEmpty()) {
            $options[] = [
                'id' => 0,
                'name' => 'Overige ruimtes',
                'areas' => $loose->map(fn (ProjectArea $area): array => [
                    'id' => (int) $area->id,
                    'label' => $area->label(),
                ])->all(),
            ];
        }

        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workItemOptions(Project $project, Worker $worker, WorkerAssignment $assignment): array
    {
        $orders = $project->relationLoaded('workOrders')
            ? $project->workOrders
            : $project->workOrders()->get();

        return $project->workItems
            ->map(function (WorkItem $item) use ($worker, $assignment, $orders): array {
                $resolved = $this->prices->resolve($worker, $item, $item->unit, null, $orders);

                return [
                    'id' => (int) $item->id,
                    'name' => $item->name,
                    'unit' => $item->unit->value,
                    'unit_label' => $item->unit->label(),
                    'suggested_price' => $resolved['price'],
                    'selected' => (int) $assignment->work_item_id === (int) $item->id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentOptions(Project $project): array
    {
        return $project->documents
            ->filter(fn (ProjectDocument $document): bool => $document->document_type === 'plattegrond' || $document->isPdf() || $document->isImage())
            ->map(fn (ProjectDocument $document): array => [
                'id' => (int) $document->id,
                'name' => $document->original_filename,
                'floor' => $this->floors->fromFilename($document->original_filename),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ProjectArea>  $pool
     * @return list<int>
     */
    private function chosenAreaIds(array $payload, Collection $pool, bool $entire): array
    {
        if ($pool->isEmpty()) {
            return [];
        }

        if ($entire) {
            return $pool->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        }

        $wanted = $this->idsInProject(
            is_array($payload['area_ids'] ?? null) ? $payload['area_ids'] : [],
            $pool->pluck('id')->all(),
        );

        return $wanted;
    }

    /**
     * @param  list<mixed>  $raw
     * @param  list<int|string>  $allowed
     * @return list<int>
     */
    private function idsInProject(array $raw, array $allowed): array
    {
        $allowed = array_map(static fn (mixed $id): int => (int) $id, $allowed);

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $raw),
            static fn (int $id): bool => $id > 0 && in_array($id, $allowed, true),
        )));
    }
}
