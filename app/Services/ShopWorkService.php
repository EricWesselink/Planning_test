<?php

namespace App\Services;

use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\WorkItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShopWorkService
{
    public const ATTACHMENT_TYPE = 'bijlage';

    public function __construct(private ProjectIntakeService $intake) {}

    /**
     * @param  array{
     *     customer_name: string,
     *     city?: ?string,
     *     address?: ?string,
     *     postal_code?: ?string,
     *     work_description?: ?string,
     *     planned_start_date?: ?string,
     *     planned_end_date?: ?string,
     *     work_activity_ids: list<int>,
     *     activity_notes?: array<int|string, mixed>,
     *     activity_quantities?: array<int|string, mixed>,
     *     activity_units?: array<int|string, mixed>
     * }  $data
     * @param  list<UploadedFile>  $files
     */
    public function create(array $data, User $user, array $files = []): Project
    {
        return DB::transaction(function () use ($data, $user, $files) {
            $customer = Customer::query()->firstOrCreate(
                ['name' => $data['customer_name']],
                ['city' => $data['city'] ?? null]
            );

            $project = Project::query()->create([
                'project_number' => $this->intake->nextProjectNumber(),
                'customer_id' => $customer->id,
                'name' => $this->headline($data['customer_name'], $data['city'] ?? null),
                'address' => $data['address'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'city' => $data['city'] ?? null,
                'supervisor_user_id' => $user->id,
                'planned_start_date' => $data['planned_start_date'] ?? null,
                'planned_end_date' => $data['planned_end_date'] ?? null,
                'status' => ProjectStatus::Gepland,
                'kind' => ProjectKind::Winkel,
                'work_description' => $data['work_description'] ?? null,
                'basis_uurtarief' => $data['basis_uurtarief'] ?? null,
            ]);

            $this->syncActivities(
                $project,
                $data['work_activity_ids'],
                $data['activity_notes'] ?? [],
                $data['activity_quantities'] ?? [],
                $data['activity_units'] ?? [],
            );
            $this->storeFiles($project, $files, $user);

            return $project->fresh(['customer', 'workActivities.category', 'workItems', 'documents']) ?? $project;
        });
    }

    /**
     * @param  array{
     *     customer_name: string,
     *     city?: ?string,
     *     address?: ?string,
     *     postal_code?: ?string,
     *     work_description?: ?string,
     *     planned_start_date?: ?string,
     *     planned_end_date?: ?string,
     *     work_activity_ids: list<int>,
     *     activity_notes?: array<int|string, mixed>,
     *     activity_quantities?: array<int|string, mixed>,
     *     activity_units?: array<int|string, mixed>
     * }  $data
     * @param  list<UploadedFile>  $files
     */
    public function update(Project $project, array $data, User $user, array $files = []): Project
    {
        return DB::transaction(function () use ($project, $data, $user, $files) {
            $customer = Customer::query()->firstOrCreate(
                ['name' => $data['customer_name']],
                ['city' => $data['city'] ?? null]
            );

            $attributes = [
                'customer_id' => $customer->id,
                'name' => $this->headline($data['customer_name'], $data['city'] ?? null),
                'address' => $data['address'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'city' => $data['city'] ?? null,
                'work_description' => $data['work_description'] ?? null,
            ];
            if (array_key_exists('basis_uurtarief', $data)) {
                $attributes['basis_uurtarief'] = $data['basis_uurtarief'];
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
            );
            $this->storeFiles($project, $files, $user);

            return $project->fresh(['customer', 'workActivities.category', 'workItems', 'documents']) ?? $project;
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
        abort_unless($document->document_type === self::ATTACHMENT_TYPE, 404);

        $document->delete();
    }

    /**
     * @param  list<int>  $activityIds
     * @param  array<int|string, mixed>  $notes
     * @param  array<int|string, mixed>  $quantities
     * @param  array<int|string, mixed>  $units
     */
    private function syncActivities(Project $project, array $activityIds, array $notes, array $quantities = [], array $units = []): void
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
        $this->syncWorkItems($project, $activities, $notes, $quantities, $units);
    }

    /**
     * @param  Collection<int, WorkActivity>  $activities
     * @param  array<int|string, mixed>  $notes
     * @param  array<int|string, mixed>  $quantities
     * @param  array<int|string, mixed>  $units
     */
    private function syncWorkItems(Project $project, Collection $activities, array $notes, array $quantities = [], array $units = []): void
    {
        $keep = [];

        foreach ($activities as $index => $activity) {
            $note = trim((string) ($notes[$activity->id] ?? ''));
            $quantity = $this->parseQuantity($quantities[$activity->id] ?? null);
            $unit = $this->shopUnit($units[$activity->id] ?? null, $activity);
            $item = $project->workItems()
                ->where('work_activity_id', $activity->id)
                ->first();

            $payload = [
                'name' => $activity->name,
                'unit' => $unit,
                'ordered_quantity' => $quantity ?? 0,
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
