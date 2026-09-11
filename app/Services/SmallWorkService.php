<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\SmallWorkType;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmallWorkService
{
    public function __construct(private ProjectIntakeService $intake) {}

    /**
     * @param  array{
     *     type: string,
     *     customer_name?: ?string,
     *     project_id?: ?int,
     *     description: string,
     *     location?: ?string,
     *     date: string,
     *     hours: float|int|string,
     *     worker_id?: ?int,
     *     team_id?: ?int,
     *     work_number?: ?string
     * }  $data
     */
    public function create(array $data, User $user): Project
    {
        $type = SmallWorkType::from($data['type']);

        return DB::transaction(function () use ($data, $user, $type) {
            if ($type->attachesToExistingProject()) {
                return $this->createAttached($type, $data);
            }

            return $this->createStandalone($type, $data, $user);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createStandalone(SmallWorkType $type, array $data, User $user): Project
    {
        $hours = PlanningHours::snapHours((float) $data['hours']);
        $date = Carbon::parse($data['date'])->toDateString();
        $location = trim((string) ($data['location'] ?? ''));
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
            'city' => $location !== '' ? $location : null,
            'supervisor_user_id' => $user->id,
            'planned_start_date' => $date,
            'planned_end_date' => $date,
            'status' => ProjectStatus::Gepland,
            'kind' => $type->projectKind(),
            'basis_uurtarief' => SmallWorkType::HOURLY_RATE,
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

        return $project->fresh(['customer', 'workItems', 'assignments']) ?? $project;
    }

    /**
     * @param  array{
     *     customer_name: string,
     *     description: string,
     *     location?: ?string,
     *     date: string,
     *     hours: float|int|string,
     *     work_number?: ?string
     * }  $data
     */
    public function update(Project $project, array $data): Project
    {
        abort_unless($project->isSmallWork(), 404);

        return DB::transaction(function () use ($project, $data) {
            $hours = PlanningHours::snapHours((float) $data['hours']);
            $date = Carbon::parse($data['date'])->toDateString();
            $location = trim((string) ($data['location'] ?? ''));
            $description = trim((string) $data['description']);
            $customer = Customer::query()->firstOrCreate(
                ['name' => trim((string) $data['customer_name'])],
                ['city' => $location !== '' ? $location : null]
            );
            $workNumber = trim((string) ($data['work_number'] ?? ''));

            $project->update([
                'customer_id' => $customer->id,
                'name' => $description,
                'city' => $location !== '' ? $location : null,
                'planned_start_date' => $date,
                'planned_end_date' => $date,
                'project_number' => $workNumber !== '' ? $workNumber : $project->project_number,
                'basis_uurtarief' => SmallWorkType::HOURLY_RATE,
            ]);

            $item = $project->workItems()->first();
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

            return $project->fresh(['customer', 'workItems', 'assignments']) ?? $project;
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
        $description = trim((string) $data['description']);
        $sort = (int) $project->workItems()->max('sort_order') + 1;

        $item = $project->workItems()->create([
            'name' => $description,
            'unit' => WorkUnit::Hours,
            'ordered_quantity' => $hours,
            'begrote_uren' => $hours,
            'uurtarief' => SmallWorkType::HOURLY_RATE,
            'planned_start_date' => $date,
            'planned_end_date' => $date,
            'status' => 'gepland',
            'sort_order' => $sort,
            'is_extra_work' => true,
            'small_work_type' => $type,
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
}
