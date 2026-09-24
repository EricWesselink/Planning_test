<?php

namespace App\Support;

use App\Models\WorkerAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class AssignmentCoverage
{
    /** @var array<int, Collection<int, WorkerAssignment>> */
    private array $byWorker = [];

    /** @var array<string, Collection<int, WorkerAssignment>> */
    private array $queried = [];

    /**
     * @param  list<int>  $workerIds
     * @param  Collection<int, WorkerAssignment>  $assignments
     */
    public function flush(): void
    {
        $this->byWorker = [];
        $this->queried = [];
    }

    public function remember(array $workerIds, Collection $assignments): void
    {
        foreach ($workerIds as $workerId) {
            $this->byWorker[(int) $workerId] = collect();
        }

        foreach ($assignments as $assignment) {
            $workerId = (int) $assignment->worker_id;
            if (! isset($this->byWorker[$workerId])) {
                $this->byWorker[$workerId] = collect();
            }

            $this->byWorker[$workerId]->push($assignment);
        }
    }

    public function known(int $workerId): bool
    {
        return array_key_exists($workerId, $this->byWorker);
    }

    /**
     * @return Collection<int, WorkerAssignment>
     */
    public function onDate(int $workerId, CarbonInterface $date): Collection
    {
        $day = $date->toDateString();

        return $this->byWorker[$workerId]
            ->filter(function (WorkerAssignment $assignment) use ($day): bool {
                return $assignment->start_date->toDateString() <= $day
                    && $assignment->end_date->toDateString() >= $day;
            })
            ->values();
    }

    /**
     * @return Collection<int, WorkerAssignment>|null
     */
    public function cachedQuery(int $workerId, string $day): ?Collection
    {
        return $this->queried[$workerId.'|'.$day] ?? null;
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     */
    public function storeQuery(int $workerId, string $day, Collection $assignments): void
    {
        $this->queried[$workerId.'|'.$day] = $assignments;
    }
}
