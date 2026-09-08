<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Worker;

class WorkerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->scheduledWorkerId() === null;
    }

    public function view(User $user, Worker $worker): bool
    {
        $workerId = $user->scheduledWorkerId();

        return $workerId === null || (int) $worker->id === $workerId;
    }

    public function create(User $user): bool
    {
        return $user->canManageWorkers();
    }

    public function update(User $user, Worker $worker): bool
    {
        return $user->canManageWorkers();
    }
}
