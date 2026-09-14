<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkerAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class VakmanPlanningService
{
    /**
     * @return Collection<int, array{
     *     assignment: WorkerAssignment,
     *     project: Project,
     *     address: ?string,
     *     maps_url: ?string,
     *     work: list<string>,
     *     colleagues: list<string>
     * }>
     */
    public function forUser(User $user, CarbonInterface $today): Collection
    {
        $workerId = $user->scheduledWorkerId();
        if ($workerId === null) {
            return collect();
        }

        $horizon = $today->copy()->addWeeks(8)->endOfDay();

        $own = WorkerAssignment::query()
            ->with([
                'project.customer',
                'project.workItems',
                'project.workActivities.category',
                'worker',
                'crewMembers',
                'workItem',
            ])
            ->where('worker_id', $workerId)
            ->whereDate('end_date', '>=', $today)
            ->whereDate('start_date', '<=', $horizon)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $projectIds = $own->pluck('project_id')->unique()->filter()->values();
        $colleagues = $projectIds->isEmpty()
            ? collect()
            : WorkerAssignment::query()
                ->with(['worker', 'crewMembers'])
                ->whereIn('project_id', $projectIds)
                ->where('worker_id', '!=', $workerId)
                ->whereDate('end_date', '>=', $today)
                ->whereDate('start_date', '<=', $horizon)
                ->orderBy('start_date')
                ->orderBy('id')
                ->get();

        return $own
            ->filter(fn (WorkerAssignment $assignment): bool => $assignment->project !== null)
            ->map(function (WorkerAssignment $assignment) use ($user, $colleagues): array {
                $project = $assignment->project;

                return [
                    'assignment' => $assignment,
                    'project' => $project,
                    'address' => $project->nawLine(),
                    'maps_url' => $project->googleMapsUrl(),
                    'work' => $this->workNames($assignment),
                    'colleagues' => $this->colleagueNames($user, $assignment, $colleagues),
                ];
            })
            ->values();
    }

    /**
     * @return list<string>
     */
    private function workNames(WorkerAssignment $assignment): array
    {
        $names = [];
        $itemName = trim((string) ($assignment->workItem?->name ?? ''));
        if ($itemName !== '') {
            $names[] = $itemName;
        }

        $project = $assignment->project;
        if ($project === null) {
            return $names;
        }

        foreach ($project->workItems as $item) {
            $name = trim((string) $item->name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $shop = $project->shopWorkLine();
        if ($shop !== null && $shop !== '') {
            $names[] = $shop;
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $others
     * @return list<string>
     */
    private function colleagueNames(User $user, WorkerAssignment $assignment, Collection $others): array
    {
        $ownLabels = array_values(array_filter([
            trim((string) $user->name),
            $assignment->worker?->planName(),
        ], fn (string $name): bool => $name !== ''));

        $overlapping = $others->filter(function (WorkerAssignment $other) use ($assignment): bool {
            return (int) $other->project_id === (int) $assignment->project_id
                && $other->start_date->lte($assignment->end_date)
                && $other->end_date->gte($assignment->start_date);
        });

        return collect($assignment->presentNames())
            ->concat($overlapping->flatMap(function (WorkerAssignment $row): array {
                $names = $row->presentNames();
                if ($names !== []) {
                    return $names;
                }

                $team = trim((string) ($row->worker?->planName() ?? ''));

                return $team !== '' ? [$team] : [];
            }))
            ->filter(fn (string $name): bool => $name !== '' && ! in_array($name, $ownLabels, true))
            ->unique()
            ->values()
            ->all();
    }
}
