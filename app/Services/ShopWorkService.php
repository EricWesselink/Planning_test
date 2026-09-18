<?php

namespace App\Services;

use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\SmallWorkType;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Support\PlanningHours;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopWorkService
{
    public const ATTACHMENT_TYPE = 'bijlage';

    public function __construct(
        private ProjectIntakeService $intake,
        private PlanningFitService $fit,
        private MeasurementFormService $measurements,
    ) {}

    /**
     * @param  array{
     *     customer_name: string,
     *     city?: ?string,
     *     address?: ?string,
     *     postal_code?: ?string,
     *     contact_phone?: ?string,
     *     contact_email?: ?string,
     *     work_description?: ?string,
     *     planned_start_date?: ?string,
     *     planned_end_date?: ?string,
     *     work_activity_ids: list<int>,
     *     activity_notes?: array<int|string, mixed>,
     *     activity_quantities?: array<int|string, mixed>,
     *     activity_hours?: array<int|string, mixed>,
     *     activity_units?: array<int|string, mixed>,
     *     worker_id?: ?int,
     *     order_amount?: string|int|float|null
     * }  $data
     * @param  list<UploadedFile>  $files
     */
    public function create(array $data, User $user, array $files = []): Project
    {
        return DB::transaction(function () use ($data, $user, $files) {
            $customer = $this->syncCustomer($data);

            $project = Project::query()->create([
                'project_number' => $this->intake->nextProjectNumber(),
                'customer_id' => $customer->id,
                'name' => $this->headline($data['customer_name'], $data['city'] ?? null),
                'address' => $data['address'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'city' => $data['city'] ?? null,
                'contact_phone' => $this->nullableString($data['contact_phone'] ?? null),
                'contact_email' => $this->nullableString($data['contact_email'] ?? null),
                'supervisor_user_id' => $user->id,
                'planned_start_date' => $data['planned_start_date'] ?? null,
                'planned_end_date' => $data['planned_end_date'] ?? null,
                'status' => ProjectStatus::Gepland,
                'kind' => ProjectKind::Winkel,
                'work_description' => $data['work_description'] ?? null,
                'basis_uurtarief' => $data['basis_uurtarief'] ?? SmallWorkType::HOURLY_RATE,
                'order_amount' => $data['order_amount'] ?? null,
            ]);

            $this->syncActivities(
                $project,
                $data['work_activity_ids'],
                $data['activity_notes'] ?? [],
                $data['activity_quantities'] ?? [],
                $data['activity_units'] ?? [],
                $data['activity_hours'] ?? [],
            );
            $this->assignPreferredWorker($project->fresh(['workItems', 'assignments']) ?? $project, $this->preferredWorkerId($data));
            $this->storeFiles($project, $files, $user);
            $this->syncMeasurement($project, $data);

            return $project->fresh(['customer', 'workActivities.category', 'workItems', 'documents', 'assignments.worker', 'measurementForm.rows', 'measurementForm.meter']) ?? $project;
        });
    }

    /**
     * @param  array{
     *     customer_name: string,
     *     city?: ?string,
     *     address?: ?string,
     *     postal_code?: ?string,
     *     contact_phone?: ?string,
     *     contact_email?: ?string,
     *     work_description?: ?string,
     *     planned_start_date?: ?string,
     *     planned_end_date?: ?string,
     *     work_activity_ids: list<int>,
     *     activity_notes?: array<int|string, mixed>,
     *     activity_quantities?: array<int|string, mixed>,
     *     activity_hours?: array<int|string, mixed>,
     *     activity_units?: array<int|string, mixed>,
     *     worker_id?: ?int,
     *     order_amount?: string|int|float|null
     * }  $data
     * @param  list<UploadedFile>  $files
     */
    public function update(Project $project, array $data, User $user, array $files = []): Project
    {
        return DB::transaction(function () use ($project, $data, $user, $files) {
            $customer = $this->syncCustomer($data);

            $attributes = [
                'customer_id' => $customer->id,
                'name' => $this->headline($data['customer_name'], $data['city'] ?? null),
                'address' => $data['address'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'city' => $data['city'] ?? null,
                'contact_phone' => $this->nullableString($data['contact_phone'] ?? null),
                'contact_email' => $this->nullableString($data['contact_email'] ?? null),
                'work_description' => $data['work_description'] ?? null,
            ];
            if (array_key_exists('basis_uurtarief', $data)) {
                $attributes['basis_uurtarief'] = $data['basis_uurtarief'];
            }
            if (array_key_exists('order_amount', $data)) {
                $attributes['order_amount'] = $data['order_amount'];
            }
            $project->update($attributes);

            if (array_key_exists('planned_start_date', $data) || array_key_exists('planned_end_date', $data)) {
                $project->forceFill([
                    'planned_start_date' => $data['planned_start_date'] ?? $project->planned_start_date,
                    'planned_end_date' => $data['planned_end_date'] ?? $project->planned_end_date,
                ])->save();
            }

            $this->syncActivities(
                $project,
                $data['work_activity_ids'],
                $data['activity_notes'] ?? [],
                $data['activity_quantities'] ?? [],
                $data['activity_units'] ?? [],
                $data['activity_hours'] ?? [],
            );
            $this->assignPreferredWorker($project->fresh(['workItems', 'assignments']) ?? $project, $this->preferredWorkerId($data));
            $this->storeFiles($project, $files, $user);
            $this->syncMeasurement($project, $data);

            return $project->fresh(['customer', 'workActivities.category', 'workItems', 'documents', 'assignments.worker', 'measurementForm.rows', 'measurementForm.meter']) ?? $project;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assignPreferredWorker(Project $project, ?int $workerId): void
    {
        $project->loadMissing(['workItems', 'assignments']);
        $start = $project->planned_start_date;
        $end = $project->planned_end_date;
        $item = $project->workItems
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->first(fn (WorkItem $item): bool => ! $item->isIntakeTask());

        if ($workerId === null || ! $item instanceof WorkItem || $start === null || $end === null) {
            return;
        }

        $worker = Worker::query()->findOrFail($workerId);
        $message = $this->fit->shopRejection($worker, $start, $end, (int) $project->id);
        if ($message !== null) {
            throw ValidationException::withMessages([
                'worker_id' => $message,
            ]);
        }

        $assignments = $project->assignments;
        $workerIds = $assignments
            ->pluck('worker_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($workerIds->count() > 1) {
            throw ValidationException::withMessages([
                'worker_id' => 'Er staan al meerdere vakmannen op dit werk. Wijzig dat in de planning.',
            ]);
        }

        if ($assignments->isEmpty()) {
            $assignment = new WorkerAssignment([
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $item->id,
                'people_count' => 1,
            ]);
            $assignment->applySchedule($start, $end, PlanningHours::DAY_START, PlanningHours::DAY_END);
            $assignment->save();

            return;
        }

        $sameWorker = $workerIds->count() === 1 && (int) $workerIds->first() === (int) $worker->id;
        $sameDates = $assignments->every(
            fn (WorkerAssignment $assignment): bool => $assignment->start_date?->isSameDay($start) && $assignment->end_date?->isSameDay($end)
        );
        if ($sameWorker && $sameDates) {
            return;
        }

        foreach ($assignments as $assignment) {
            $assignment->worker_id = $worker->id;
            $assignment->work_item_id = $assignment->work_item_id ?: $item->id;
            $assignment->applySchedule($start, $end, PlanningHours::DAY_START, PlanningHours::DAY_END);
            $assignment->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function preferredWorkerId(array $data): ?int
    {
        $workerId = (int) ($data['worker_id'] ?? 0);

        return $workerId > 0 ? $workerId : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMeasurement(Project $project, array $data): void
    {
        if (! array_key_exists('measurement', $data) || ! is_array($data['measurement'])) {
            return;
        }

        $this->measurements->sync($project, $data['measurement']);
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
        abort_unless($document->document_type === self::ATTACHMENT_TYPE, 404);

        $document->delete();
    }

    /**
     * @param  list<int>  $activityIds
     * @param  array<int|string, mixed>  $notes
     * @param  array<int|string, mixed>  $quantities
     * @param  array<int|string, mixed>  $units
     * @param  array<int|string, mixed>  $hours
     */
    private function syncActivities(Project $project, array $activityIds, array $notes, array $quantities = [], array $units = [], array $hours = []): void
    {
        $ids = collect($activityIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

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
            $note = trim((string) ($notes[$activity->id] ?? ''));
            $quantity = $this->parseQuantity($quantities[$activity->id] ?? null);
            $unit = $this->shopUnit($units[$activity->id] ?? null, $activity);
            $sync[$activity->id] = [
                'notes' => $note === '' ? null : $note,
                'quantity' => $quantity,
                'unit' => $unit,
                'sort_order' => $index + 1,
            ];
        }

        $project->workActivities()->sync($sync);
        $this->syncWorkItems($project, $activities, $notes, $quantities, $units, $hours);
    }

    /**
     * @param  Collection<int, WorkActivity>  $activities
     * @param  array<int|string, mixed>  $notes
     * @param  array<int|string, mixed>  $quantities
     * @param  array<int|string, mixed>  $units
     * @param  array<int|string, mixed>  $hours
     */
    private function syncWorkItems(Project $project, Collection $activities, array $notes, array $quantities = [], array $units = [], array $hours = []): void
    {
        $keep = [];

        foreach ($activities as $index => $activity) {
            $note = trim((string) ($notes[$activity->id] ?? ''));
            $quantity = $this->parseQuantity($quantities[$activity->id] ?? null);
            $budgetHours = $this->parseQuantity($hours[$activity->id] ?? null);
            $unit = $this->shopUnit($units[$activity->id] ?? null, $activity);
            $item = $project->workItems()
                ->where('work_activity_id', $activity->id)
                ->first();

            $payload = [
                'name' => $activity->name,
                'unit' => $unit,
                'ordered_quantity' => $quantity ?? 0,
                'begrote_uren' => $budgetHours,
                'uurtarief' => $project->basis_uurtarief,
                'status' => $item?->status ?? 'gepland',
                'sort_order' => $index + 1,
                'notes' => $note === '' ? null : $note,
                'work_activity_id' => $activity->id,
                'planned_start_date' => $item?->planned_start_date ?? $project->planned_start_date,
                'planned_end_date' => $item?->planned_end_date ?? $project->planned_end_date,
            ];

            if ($item) {
                $item->update($payload);
            } else {
                $item = $project->workItems()->create($payload);
            }

            $keep[] = (int) $item->id;
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
     * @param  array<string, mixed>  $data
     */
    private function syncCustomer(array $data): Customer
    {
        $customer = Customer::query()->firstOrCreate(
            ['name' => $data['customer_name']],
            ['city' => $data['city'] ?? null]
        );

        $customer->fill([
            'city' => $data['city'] ?? $customer->city,
            'address' => $data['address'] ?? $customer->address,
            'postal_code' => $data['postal_code'] ?? $customer->postal_code,
            'phone' => $this->nullableString($data['contact_phone'] ?? null),
            'email' => $this->nullableString($data['contact_email'] ?? null),
        ])->save();

        return $customer;
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function headline(string $customer, ?string $city): string
    {
        $parts = array_filter([trim($customer), trim((string) $city)], fn (string $part): bool => $part !== '');

        return implode(' - ', $parts);
    }

    private function parseQuantity(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $quantity = round((float) $value, 2);

        return $quantity > 0 ? $quantity : null;
    }

    private function shopUnit(mixed $value, WorkActivity $activity): WorkUnit
    {
        $unit = WorkUnit::tryFrom((string) $value);

        if (in_array($unit, WorkUnit::shopCases(), true)) {
            return $unit;
        }

        return $activity->defaultShopUnit();
    }
}
