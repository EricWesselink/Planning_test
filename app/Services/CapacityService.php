<?php

namespace App\Services;

use App\Models\Worker;
use App\Models\WorkerAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class CapacityService
{
    /**
     * @param  Collection<int, CarbonInterface>  $days
     * @return array<string, array{total:int,scheduled:int}>
     */
    public function forDays(Collection $days): array
    {
        $total = Worker::query()->where('active', true)->count();
        $assignments = WorkerAssignment::query()
            ->whereDate('end_date', '>=', $days->first())
            ->whereDate('start_date', '<=', $days->last())
            ->get();

        $result = [];
        foreach ($days as $day) {
            $scheduled = $assignments
                ->filter(fn (WorkerAssignment $assignment) => $assignment->coversDate($day))
                ->pluck('worker_id')
                ->unique()
                ->count();

            $result[$day->toDateString()] = [
                'total' => $total,
                'scheduled' => $scheduled,
            ];
        }

        return $result;
    }
}
