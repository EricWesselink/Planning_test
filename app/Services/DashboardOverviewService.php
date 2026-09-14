<?php

namespace App\Services;

use App\Enums\FlooringSpecialty;
use App\Enums\WorkPhase;
use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\WorkActivity;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DashboardOverviewService
{
    /**
     * @param  Collection<int, array<string, mixed>>  $todayBlocks
     * @param  Collection<int, Project>  $running
     * @param  Collection<int, Project>  $upcoming
     * @return array{
     *     work_count: int,
     *     egaliseren_m2: float,
     *     laying_m2: float,
     *     planned_week_m2: float,
     *     unmanned_count: int,
     *     running_count: int,
     *     today_count: int
     * }
     */
    public function summarize(
        CarbonInterface $today,
        Collection $todayBlocks,
        Collection $running,
        Collection $upcoming,
    ): array {
        $works = $running->concat($upcoming)->unique('id')->values();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(5);

        $egaliseren = 0.0;
        $laying = 0.0;

        foreach ($works as $project) {
            foreach ($this->squareMeterTotals($project) as $bucket => $quantity) {
                if ($bucket === 'egaliseren') {
                    $egaliseren += $quantity;
                }

                if ($bucket === 'laying') {
                    $laying += $quantity;
                }
            }
        }

        return [
            'work_count' => $works->count(),
            'egaliseren_m2' => $egaliseren,
            'laying_m2' => $laying,
            'planned_week_m2' => $this->plannedWeekSquareMeters($works, $weekStart, $weekEnd),
            'unmanned_count' => $upcoming
                ->filter(fn (Project $project): bool => $project->assignments->isEmpty() && $project->workOrders->isEmpty())
                ->count(),
            'running_count' => $running->count(),
            'today_count' => $todayBlocks->count(),
        ];
    }

    /**
     * @return array{egaliseren: float, laying: float}
     */
    private function squareMeterTotals(Project $project): array
    {
        $totals = ['egaliseren' => 0.0, 'laying' => 0.0];
        $items = $project->workItems
            ->filter(fn (WorkItem $item): bool => $this->countsAsSquareMeters($item));

        if ($items->isNotEmpty()) {
            foreach ($items as $item) {
                $bucket = $this->itemBucket($item);
                if ($bucket !== null) {
                    $totals[$bucket] += (float) $item->ordered_quantity;
                }
            }

            return $totals;
        }

        foreach ($project->workActivities as $activity) {
            $quantity = (float) ($activity->pivot?->quantity ?? 0);
            $unit = $activity->pivot?->unit;
            if ($quantity <= 0.0001 || $unit !== WorkUnit::SquareMeter) {
                continue;
            }

            $bucket = $this->activityBucket($activity);
            if ($bucket !== null) {
                $totals[$bucket] += $quantity;
            }
        }

        return $totals;
    }

    /**
     * @param  Collection<int, Project>  $works
     */
    private function plannedWeekSquareMeters(Collection $works, CarbonInterface $weekStart, CarbonInterface $weekEnd): float
    {
        $assignments = $works
            ->flatMap(fn (Project $project): Collection => $project->assignments
                ->filter(fn (WorkerAssignment $assignment): bool => $assignment->start_date->lte($weekEnd)
                    && $assignment->end_date->gte($weekStart))
                ->map(function (WorkerAssignment $assignment) use ($project): WorkerAssignment {
                    $assignment->setRelation('project', $project);

                    return $assignment;
                }))
            ->values();

        $specific = $assignments->filter(fn (WorkerAssignment $assignment): bool => (int) $assignment->work_item_id > 0);
        $projectsWithSpecific = $specific
            ->map(fn (WorkerAssignment $assignment): int => (int) $assignment->project_id)
            ->unique()
            ->all();
        $generic = $assignments->filter(
            fn (WorkerAssignment $assignment): bool => (int) $assignment->work_item_id <= 0
                && ! in_array((int) $assignment->project_id, $projectsWithSpecific, true)
        );

        $planned = 0.0;

        foreach ($specific->groupBy(fn (WorkerAssignment $assignment): int => (int) $assignment->work_item_id) as $itemId => $group) {
            $item = $group->first()?->project?->workItems->firstWhere('id', (int) $itemId);
            if (! $item instanceof WorkItem || ! $this->countsAsSquareMeters($item) || $this->itemBucket($item) === null) {
                continue;
            }

            $planned += $this->proratedQuantity((float) $item->ordered_quantity, $group, $weekStart, $weekEnd);
        }

        foreach ($generic->groupBy(fn (WorkerAssignment $assignment): int => (int) $assignment->project_id) as $group) {
            $project = $group->first()?->project;
            if (! $project instanceof Project) {
                continue;
            }

            $quantity = array_sum($this->squareMeterTotals($project));
            $planned += $this->proratedQuantity($quantity, $group, $weekStart, $weekEnd);
        }

        return $planned;
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     */
    private function proratedQuantity(
        float $quantity,
        Collection $assignments,
        CarbonInterface $weekStart,
        CarbonInterface $weekEnd,
    ): float {
        if ($quantity <= 0.0001 || $assignments->isEmpty()) {
            return 0.0;
        }

        $spanStart = $assignments->min('start_date');
        $spanEnd = $assignments->max('end_date');
        if (! $spanStart instanceof CarbonInterface || ! $spanEnd instanceof CarbonInterface) {
            return 0.0;
        }

        $spanDays = $this->inclusiveDays($spanStart, $spanEnd);
        $overlapDays = $this->overlapDays($spanStart, $spanEnd, $weekStart, $weekEnd);
        if ($spanDays <= 0 || $overlapDays <= 0) {
            return 0.0;
        }

        return $quantity * ($overlapDays / $spanDays);
    }

    private function countsAsSquareMeters(WorkItem $item): bool
    {
        return ! $item->isExtraWork() && $item->unit === WorkUnit::SquareMeter;
    }

    private function itemBucket(WorkItem $item): ?string
    {
        if ($item->phase()->group() === 'ondergrond' || $item->specialtyKey() === FlooringSpecialty::PrimenEgaliseren->value) {
            return 'egaliseren';
        }

        if ($item->phase() === WorkPhase::Vloer) {
            return 'laying';
        }

        return null;
    }

    private function activityBucket(WorkActivity $activity): ?string
    {
        $specialty = FlooringSpecialty::tryFromLabel($activity->name)
            ?? FlooringSpecialty::tryFromLabel((string) $activity->slug);

        if ($specialty === FlooringSpecialty::PrimenEgaliseren) {
            return 'egaliseren';
        }

        if (in_array($specialty, [
            FlooringSpecialty::Pvc,
            FlooringSpecialty::Vinyl,
            FlooringSpecialty::Linoleum,
            FlooringSpecialty::Tapijt,
            FlooringSpecialty::Gietvloer,
            FlooringSpecialty::Coating,
            FlooringSpecialty::Entreemat,
        ], true)) {
            return 'laying';
        }

        return null;
    }

    private function overlapDays(
        CarbonInterface $start,
        CarbonInterface $end,
        CarbonInterface $weekStart,
        CarbonInterface $weekEnd,
    ): int {
        $from = $start->copy()->startOfDay();
        $to = $end->copy()->startOfDay();

        if ($from->lt($weekStart)) {
            $from = $weekStart->copy();
        }
        if ($to->gt($weekEnd)) {
            $to = $weekEnd->copy();
        }

        if ($to->lt($from)) {
            return 0;
        }

        return $this->inclusiveDays($from, $to);
    }

    private function inclusiveDays(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) round($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay())) + 1;
    }
}
