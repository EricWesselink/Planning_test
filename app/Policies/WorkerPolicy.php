<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Worker;

class WorkerPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->scheduledWorkerId() !== null) {
            return false;
        }

        return $user->isVakman() || $user->canViewWorkers();
    }

    public function exportWhatsAppContacts(User $user): bool
    {
        return $user->canViewWorkers();
    }

    public function view(User $user, Worker $worker): bool
    {
        $workerId = $user->scheduledWorkerId();
        if ($workerId !== null) {
            return (int) $worker->id === $workerId;
        }

        return $user->canViewWorkers();
    }

    public function create(User $user): bool
    {
        return $user->canCreateWorkers();
    }

    public function update(User $user, Worker $worker): bool
    {
        return $user->canManageWorkers();
    }

    public function delete(User $user, Worker $worker): bool
    {
        return $user->canDeleteWorkers();
    }
}
