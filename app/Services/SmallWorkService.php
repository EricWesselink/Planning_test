<?php

namespace App\Services;

use App\Enums\ContactRole;
use App\Enums\ProjectStatus;
use App\Enums\SmallWorkType;
use App\Enums\WorkPhase;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SmallWorkService
{
    public const ATTACHMENT_TYPE = ShopWorkService::ATTACHMENT_TYPE;

    public function __construct(private ProjectIntakeService $intake) {}

    /**
     * @param  array{
     *     type: string,
     *     customer_name?: ?string,
     *     project_id?: ?int,
     *     description: string,
     *     address?: ?string,
     *     postal_code?: ?string,
     *     location?: ?string,
     *     date: string,
     *     klaar_date?: ?string,
     *     quantity?: float|int|string|null,
     *     lines?: array<int, array<string, mixed>>,
     *     hours: float|int|string,
     *     worker_id?: ?int,
     *     team_id?: ?int,
     *     work_number?: ?string,
     *     work_activity_ids?: array<int, mixed>,
     *     activity_quantities?: array<int|string, mixed>,
     *     activity_notes?: array<int|string, mixed>,
     *     contact_name?: ?string,
     *     contact_phone?: ?string,
     *     contact_role?: ?string
     * }  $data
     * @param  list<UploadedFile>  $files
     */
    public function create(array $data, User $user, array $files = []): Project
    {
        $type = SmallWorkType::from($data['type']);

        return DB::transaction(function () use ($data, $user, $type, $files) {
            if ($type->attachesToExistingProject()) {
                return $this->createAttached($type, $data);
            }

            return $this->createStandalone($type, $data, $user, $files);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     */
    private function createStandalone(SmallWorkType $type, array $data, User $user, array $files = []): Project
    {
        $hours = PlanningHours::snapHours((float) $data['hours']);
        $date = Carbon::parse($data['date'])->toDateString();
        $location = trim((string) ($data['location'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));
        $postalCode = trim((string) ($data['postal_code'] ?? ''));
        $description = trim((string) $data['description']);
        $customer = Customer::query()->firstOrCreate(
            ['name' => trim((string) $data['customer_name'])],
            ['city' => $location !== '' ? $location : null]
        );

        $project = Project::query()->create([
            'project_number' => trim((string) ($data['work_number'] ?? '')) !== ''
                ? trim((string) $data['work_number'])
                : $this->intake->nextProjectNumber(),
            'customer_id' => $customer->id,
            'name' => $description,
            'address' => $address !== '' ? $address : null,
            'postal_code' => $postalCode !== '' ? $postalCode : null,
            'city' => $location !== '' ? $location : null,
            'supervisor_user_id' => $user->id,
            'planned_start_date' => $date,
            'planned_end_date' => $date,
            'status' => ProjectStatus::Gepland,
            'kind' => $type->projectKind(),
            'basis_uurtarief' => SmallWorkType::HOURLY_RATE,
            ...$this->contactAttributes($data),
        ]);

        $item = $project->workItems()->create([
            'name' => $description,
            'unit' => WorkUnit::Hours,
            'ordered_quantity' => $hours,
            'begrote_uren' => $hours,
            'uurtarief' => SmallWorkType::HOURLY_RATE,
            'planned_start_date' => $date,
            'planned_end_date' => $date,
            'status' => 'gepland',
            'sort_order' => 1,
        ]);

        $this->schedule($project, $item, $data, $date, $hours);
        $this->syncWorkActivities($project, $data);
        $this->storeFiles($project, $files, $user);

        return $project->fresh(['customer', 'workItems', 'assignments', 'documents', 'workActivities']) ?? $project;
    }

    /**
     * @param  array{
     *     customer_name: string,
     *     description: string,
     *     address?: ?string,
     *     postal_code?: ?string,
     *     location?: ?string,
     *     date: string,
     *     hours: float|int|string,
     *     work_number?: ?string,
     *     work_activity_ids?: array<int, mixed>,
     *     activity_quantities?: array<int|string, mixed>,
     *     activity_notes?: array<int|string, mixed>,
     *     contact_name?: ?string,
     *     contact_phone?: ?string,
     *     contact_role?: ?string
     * }  $data
     * @param  list<UploadedFile>  $files
     */
    public function update(Project $project, array $data, User $user, array $files = []): Project
    {
        abort_unless($project->isSmallWork(), 404);

        return DB::transaction(function () use ($project, $data, $user, $files) {
            $hours = PlanningHours::snapHours((float) $data['hours']);
            $date = Carbon::parse($data['date'])->toDateString();
            $location = trim((string) ($data['location'] ?? ''));
            $address = trim((string) ($data['address'] ?? ''));
            $postalCode = trim((string) ($data['postal_code'] ?? ''));
            $description = trim((string) $data['description']);
            $customer = Customer::query()->firstOrCreate(
                ['name' => trim((string) $data['customer_name'])],
                ['city' => $location !== '' ? $location : null]
            );
            $workNumber = trim((string) ($data['work_number'] ?? ''));

            $project->update([
                'customer_id' => $customer->id,
                'name' => $description,
                'address' => $address !== '' ? $address : null,
                'postal_code' => $postalCode !== '' ? $postalCode : null,
                'city' => $location !== '' ? $location : null,
                'planned_start_date' => $date,
                'planned_end_date' => $date,
                'project_number' => $workNumber !== '' ? $workNumber : $project->project_number,
                'basis_uurtarief' => SmallWorkType::HOURLY_RATE,
                ...$this->contactAttributes($data),
            ]);

            $item = $this->hoursWorkItem($project);
            if ($item instanceof WorkItem) {
                $item->update([
                    'name' => $description,
                    'ordered_quantity' => $hours,
                    'begrote_uren' => $hours,
                    'uurtarief' => SmallWorkType::HOURLY_RATE,
                    'planned_start_date' => $date,
                    'planned_end_date' => $date,
                ]);
                $this->reschedule($item, $date, $hours);
            }

            $this->syncWorkActivities($project, $data);
            $this->storeFiles($project, $files, $user);

            return $project->fresh(['customer', 'workItems', 'assignments', 'documents', 'workActivities']) ?? $project;
        });
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function storeFiles(Project $project, array $files, User $user): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $this->intake->storeDocument($project, $file, self::ATTACHMENT_TYPE, $user);
        }
    }

    public function deleteAttachment(Project $project, ProjectDocument $document): void
    {
        abort_unless((int) $document->project_id === (int) $project->id, 404);
        abort_unless(in_array($document->document_type, [self::ATTACHMENT_TYPE, 'plattegrond'], true), 404);

        $document->delete();
    }

    /**
     * @param  array{
     *     description: string,
     *     date: string,
     *     klaar_date?: ?string,
     *     hours: float|int|string,
     *     quantity?: float|int|string|null,
     *     lines?: array<int, array<string, mixed>>,
     *     actual_hours?: float|int|string|null,
     *     completed_quantity?: float|int|string|null
     * }  $data
     */
    public function updateAttached(WorkItem $item, array $data, User $user): WorkItem
    {
        abort_unless($item->isExtraWork(), 404);

        return DB::transaction(function () use ($item, $data, $user) {
            $item->loadMissing(['assignments', 'progressEntries', 'project']);
            $hours = PlanningHours::snapHours((float) $data['hours']);
            $date = Carbon::parse($data['date'])->toDateString();
            $klaar = filled($data['klaar_date'] ?? null)
                ? Carbon::parse((string) $data['klaar_date'])->toDateString()
                : $date;
            $lines = $this->extraLinesFrom($data);
            $quantity = round(array_sum(array_column($lines, 'quantity')), 2);
            $hasMaterial = $quantity > 0.0001;
            $description = trim((string) $data['description']);

            $item->update([
                'name' => $description,
                'unit' => $hasMaterial ? WorkUnit::SquareMeter : WorkUnit::Hours,
                'ordered_quantity' => $hasMaterial ? $quantity : $hours,
                'begrote_uren' => $hours,
                'begrote_hoeveelheid' => $hasMaterial ? $quantity : null,
                'uurtarief' => SmallWorkType::HOURLY_RATE,
                'planned_start_date' => $date,
                'planned_end_date' => $klaar,
                'extra_lines' => $lines === [] ? null : $lines,
            ]);
            $this->reschedule($item->fresh('assignments') ?? $item, $date, $hours);

            $actualHours = is_numeric($data['actual_hours'] ?? null) ? (float) $data['actual_hours'] : 0.0;
            $completed = round(array_sum(array_column($lines, 'completed')), 2);
            if ($completed <= 0.0001 && is_numeric($data['completed_quantity'] ?? null)) {
                $completed = (float) $data['completed_quantity'];
            }
            if ($actualHours > 0.0001 || $completed > 0.0001) {
                $workerId = $item->assignments->first()?->worker_id;
                if ($workerId === null) {
                    throw ValidationException::withMessages([
                        'actual_hours' => 'Plan eerst een vakman in.',
                    ]);
                }

                $doneQty = $completed > 0.0001
                    ? $completed
                    : ($hasMaterial ? $quantity : $hours);
                $entry = $item->progressEntries->first();
                $payload = [
                    'project_id' => $item->project_id,
                    'work_item_id' => $item->id,
                    'worker_id' => $workerId,
                    'date' => $klaar,
                    'completed_quantity' => $doneQty,
                    'unit' => $hasMaterial ? WorkUnit::SquareMeter : WorkUnit::Hours,
                    'worked_hours' => $actualHours,
                    'note' => $description,
                    'created_by' => $user->id,
                ];
                if ($entry instanceof WorkProgressEntry) {
                    $entry->update($payload);
                } else {
                    WorkProgressEntry::query()->create($payload);
                }
                $item->syncStatusFromProgress();
            }

            return $item->fresh(['assignments', 'progressEntries']) ?? $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createAttached(SmallWorkType $type, array $data): Project
    {
        $project = Project::query()->findOrFail($data['project_id']);
        $hours = PlanningHours::snapHours((float) $data['hours']);
        $date = Carbon::parse($data['date'])->toDateString();
        $klaar = filled($data['klaar_date'] ?? null)
            ? Carbon::parse((string) $data['klaar_date'])->toDateString()
            : $date;
        $lines = $this->extraLinesFrom($data);
        $quantity = round(array_sum(array_column($lines, 'quantity')), 2);
        $hasMaterial = $quantity > 0.0001;
        $description = trim((string) $data['description']);
        $sort = (int) $project->workItems()->max('sort_order') + 1;

        $item = $project->workItems()->create([
            'name' => $description,
            'unit' => $hasMaterial ? WorkUnit::SquareMeter : WorkUnit::Hours,
            'ordered_quantity' => $hasMaterial ? $quantity : $hours,
            'begrote_uren' => $hours,
            'begrote_hoeveelheid' => $hasMaterial ? $quantity : null,
            'uurtarief' => SmallWorkType::HOURLY_RATE,
            'planned_start_date' => $date,
            'planned_end_date' => $klaar,
            'status' => 'gepland',
            'sort_order' => $sort,
            'is_extra_work' => true,
            'small_work_type' => $type,
            'extra_lines' => $lines === [] ? null : $lines,
        ]);

        $this->schedule($project, $item, $data, $date, $hours);

        return $project->fresh(['customer', 'workItems', 'assignments']) ?? $project;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function schedule(Project $project, WorkItem $item, array $data, string $date, int $hours): void
    {
        $workers = $this->workersFor($data);
        if ($workers->isEmpty()) {
            return;
        }

        $times = PlanningHours::resolve($hours, null, null, null);
        $start = Carbon::parse($date);
        $teamId = isset($data['team_id']) ? (int) $data['team_id'] : null;

        foreach ($workers as $worker) {
            $assignment = new WorkerAssignment([
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $item->id,
                'team_id' => $teamId > 0 ? $teamId : null,
                'people_count' => 1,
            ]);
            $assignment->applySchedule($start, $start, $times['start_time'], $times['end_time']);
            $assignment->save();
        }
    }

    private function reschedule(WorkItem $item, string $date, int $hours): void
    {
        $times = PlanningHours::resolve($hours, null, null, null);
        $start = Carbon::parse($date);

        foreach ($item->assignments as $assignment) {
            $assignment->applySchedule($start, $start, $times['start_time'], $times['end_time']);
            $assignment->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, Worker>
     */
    private function workersFor(array $data): Collection
    {
        if (! empty($data['team_id'])) {
            return Team::query()->with('workers')->findOrFail($data['team_id'])->workers;
        }

        if (! empty($data['worker_id'])) {
            return collect([Worker::query()->findOrFail($data['worker_id'])]);
        }

        return collect();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncWorkActivities(Project $project, array $data): void
    {
        $ids = collect($data['work_activity_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $quantities = is_array($data['activity_quantities'] ?? null) ? $data['activity_quantities'] : [];
        $notes = is_array($data['activity_notes'] ?? null) ? $data['activity_notes'] : [];
        [$ids, $quantities, $notes] = $this->expandOndergrondSelection($ids, $quantities, $notes);

        $activities = WorkActivity::query()
            ->with('category')
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (WorkActivity $activity): array => [
                $activity->category?->sort_order ?? 0,
                $activity->sort_order,
                $activity->id,
            ])
            ->values();

        $sync = [];
        foreach ($activities as $index => $activity) {
            $quantity = $this->parseQuantity($quantities[$activity->id] ?? null);
            $sync[$activity->id] = [
                'notes' => $this->noteFrom($notes, $activity->id),
                'quantity' => $quantity,
                'unit' => $activity->defaultShopUnit(),
                'sort_order' => $index + 1,
            ];
        }

        $project->workActivities()->sync($sync);
        $this->syncActivityWorkItems($project, $activities, $quantities, $notes);
    }

    /**
     * @param  Collection<int, int>  $ids
     * @param  array<int|string, mixed>  $quantities
     * @param  array<int|string, mixed>  $notes
     * @return array{0: Collection<int, int>, 1: array<int|string, mixed>, 2: array<int|string, mixed>}
     */
    private function expandOndergrondSelection(Collection $ids, array $quantities, array $notes): array
    {
        $prep = WorkActivity::query()
            ->whereIn('slug', WorkActivity::PREP_SLUGS)
            ->get();
        if ($prep->isEmpty() || $ids->intersect($prep->modelKeys())->isEmpty()) {
            return [$ids, $quantities, $notes];
        }

        $quantity = null;
        foreach ($prep as $activity) {
            $parsed = $this->parseQuantity($quantities[$activity->id] ?? null);
            if ($parsed !== null) {
                $quantity = $quantity === null ? $parsed : max($quantity, $parsed);
            }
        }
        $primary = $prep->first(fn (WorkActivity $activity): bool => $activity->slug === 'egaliseren')
            ?? $prep->first();
        $note = $this->noteFrom($notes, $primary?->id)
            ?? $prep
                ->map(fn (WorkActivity $activity): ?string => $this->noteFrom($notes, $activity->id))
                ->filter()
                ->first();
        foreach ($prep as $activity) {
            $quantities[$activity->id] = $quantity;
            $notes[$activity->id] = $note;
        }

        return [$ids->diff($prep->modelKeys())->concat($prep->modelKeys())->unique()->values(), $quantities, $notes];
    }

    /**
     * @param  Collection<int, WorkActivity>  $activities
     * @param  array<int|string, mixed>  $quantities
     * @param  array<int|string, mixed>  $notes
     */
    private function syncActivityWorkItems(Project $project, Collection $activities, array $quantities, array $notes): void
    {
        $keep = [];
        $sort = 2;
        $prep = $activities->filter(fn (WorkActivity $activity): bool => $activity->isOndergrondPrep());
        $rest = $activities
            ->reject(fn (WorkActivity $activity): bool => $activity->isOndergrondPrep())
            ->values();

        if ($prep->isNotEmpty()) {
            $keep[] = $this->syncOndergrondWorkItem($project, $prep, $quantities, $notes, $sort);
            $sort++;
        }

        foreach ($rest as $activity) {
            $keep[] = $this->upsertActivityWorkItem(
                $project,
                $activity,
                $activity->name,
                $this->parseQuantity($quantities[$activity->id] ?? null),
                $sort,
                $this->noteFrom($notes, $activity->id)
            );
            $sort++;
        }

        $project->workItems()
            ->whereNotNull('work_activity_id')
            ->whereNotIn('id', $keep === [] ? [0] : $keep)
            ->get()
            ->each(function (WorkItem $item): void {
                $item->assignments()->update(['work_item_id' => null]);
                $item->delete();
            });
    }

    /**
     * @param  Collection<int, WorkActivity>  $prep
     * @param  array<int|string, mixed>  $quantities
     * @param  array<int|string, mixed>  $notes
     */
    private function syncOndergrondWorkItem(Project $project, Collection $prep, array $quantities, array $notes, int $sort): int
    {
        $primary = $prep->first(fn (WorkActivity $activity): bool => $activity->slug === 'egaliseren')
            ?? $prep->first();
        $quantity = $prep
            ->map(fn (WorkActivity $activity): ?float => $this->parseQuantity($quantities[$activity->id] ?? null))
            ->filter()
            ->max();
        $note = $this->noteFrom($notes, $primary?->id)
            ?? $prep
                ->map(fn (WorkActivity $activity): ?string => $this->noteFrom($notes, $activity->id))
                ->filter()
                ->first();

        $existing = $project->workItems()
            ->whereNotNull('work_activity_id')
            ->where(function ($query) use ($prep): void {
                $query->whereIn('work_activity_id', $prep->modelKeys())
                    ->orWhere('name', WorkPhase::Egaliseren->groupLabel());
            })
            ->orderBy('id')
            ->get();
        $item = $existing->first();
        foreach ($existing->skip(1) as $duplicate) {
            if ($item instanceof WorkItem) {
                $duplicate->assignments()->update(['work_item_id' => $item->id]);
                $duplicate->progressEntries()->update(['work_item_id' => $item->id]);
            }
            $duplicate->delete();
        }

        return $this->upsertActivityWorkItem(
            $project,
            $primary,
            WorkPhase::Egaliseren->groupLabel(),
            is_numeric($quantity) ? (float) $quantity : null,
            $sort,
            $note,
            $item
        );
    }

    private function upsertActivityWorkItem(
        Project $project,
        WorkActivity $activity,
        string $name,
        ?float $quantity,
        int $sort,
        ?string $note = null,
        ?WorkItem $item = null,
    ): int {
        $item ??= $project->workItems()
            ->where('work_activity_id', $activity->id)
            ->first();

        $payload = [
            'name' => $name,
            'unit' => $activity->defaultShopUnit(),
            'ordered_quantity' => $quantity ?? 0,
            'uurtarief' => $project->basis_uurtarief,
            'status' => $item?->status ?? 'gepland',
            'sort_order' => $sort,
            'notes' => $note,
            'work_activity_id' => $activity->id,
            'planned_start_date' => $item?->planned_start_date ?? $project->planned_start_date,
            'planned_end_date' => $item?->planned_end_date ?? $project->planned_end_date,
        ];

        if ($item) {
            $item->update($payload);
        } else {
            $item = $project->workItems()->create($payload);
        }

        return (int) $item->id;
    }

    private function hoursWorkItem(Project $project): ?WorkItem
    {
        return $project->workItems()
            ->whereNull('work_activity_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    private function parseQuantity(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $quantity = round((float) $value, 2);

        return $quantity > 0 ? $quantity : null;
    }

    private function noteFrom(array $notes, int|string|null $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $note = trim((string) ($notes[$id] ?? ''));

        return $note === '' ? null : $note;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{name: string, quantity: float, completed: float}>
     */
    private function extraLinesFrom(array $data): array
    {
        $lines = WorkItem::normalizeExtraLines(is_array($data['lines'] ?? null) ? $data['lines'] : []);
        if ($lines !== []) {
            return $lines;
        }

        $quantity = is_numeric($data['quantity'] ?? null) ? (float) $data['quantity'] : 0.0;
        if ($quantity <= 0.0001) {
            return [];
        }

        $completed = is_numeric($data['completed_quantity'] ?? null) ? (float) $data['completed_quantity'] : 0.0;

        return [[
            'name' => 'Materiaal',
            'quantity' => $quantity,
            'completed' => $completed,
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{contact_name?: ?string, contact_phone?: ?string, contact_role?: ?string}
     */
    private function contactAttributes(array $data): array
    {
        if (! array_key_exists('contact_name', $data)
            && ! array_key_exists('contact_phone', $data)
            && ! array_key_exists('contact_role', $data)
            && ! array_key_exists('contact_role_custom', $data)) {
            return [];
        }

        $role = trim((string) ($data['contact_role'] ?? ''));
        if ($role === ContactRole::CUSTOM) {
            $role = trim((string) ($data['contact_role_custom'] ?? ''));
        }
        if ($role === ContactRole::CUSTOM) {
            $role = '';
        }

        return [
            'contact_name' => $this->nullableString($data['contact_name'] ?? null),
            'contact_phone' => $this->nullableString($data['contact_phone'] ?? null),
            'contact_role' => $this->nullableString($role),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
