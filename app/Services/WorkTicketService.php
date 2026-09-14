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
use App\Models\WorkItem;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkTicket;
use App\Services\Meetstaat\FloorLabel;
use App\Support\Format;
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
     * @param  array<string, mixed>  $input
     */
    public function store(WorkerAssignment $assignment, array $input, User $user): WorkTicket
    {
        $assignment->loadMissing(['worker.rates', 'project.floors.areas', 'project.workItems', 'project.documents', 'project.workOrders']);

        $project = $assignment->project;
        $worker = $assignment->worker;
        if ($project === null || $worker === null) {
            throw ValidationException::withMessages([
                'assignment' => 'Deze inzet heeft geen project of vakman.',
            ]);
        }

        $selection = $this->resolveSelection($project, $input);
        $kind = WorkTicketKind::forWorker($worker);
        $billing = $kind === WorkTicketKind::Opdrachtbon
            ? WorkTicketBilling::from((string) $input['billing_method'])
            : null;

        $lines = $this->buildLines($project, $worker, $selection['area_ids'], $selection['work_item_ids'], $billing, $input);
        if ($lines === []) {
            throw ValidationException::withMessages([
                'work_item_ids' => 'Geen Meetstaat-hoeveelheid voor de geselecteerde ruimtes en werkzaamheden.',
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
    ): array {
        $totals = $this->totalsFor($project, $areaIds, $workItemIds);
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
     *     document_ids: list<int>
     * }
     */
    public function resolveSelection(Project $project, array $input): array
    {
        $project->loadMissing(['floors.areas', 'areas', 'workItems', 'documents']);

        $floorsInput = is_array($input['floors'] ?? null) ? $input['floors'] : [];
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

        return [
            'floors' => $selectedFloors,
            'area_ids' => $areaIds,
            'work_item_ids' => $workItemIds,
            'document_ids' => $documentIds,
        ];
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
