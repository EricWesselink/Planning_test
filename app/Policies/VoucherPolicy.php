<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Voucher;

class VoucherPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Voucher $voucher): bool
    {
        $project = $voucher->relationLoaded('project')
            ? $voucher->project
            : $voucher->project()->first();

        if ($project === null || ! $user->canAccessProject($project)) {
            return false;
        }

        $workerId = $user->scheduledWorkerId();

        return $workerId === null || (int) $voucher->worker_id === $workerId;
    }

    public function create(User $user, ?Project $project = null): bool
    {
        if (! $user->canManageProjects()) {
            return false;
        }

        return $project === null || $user->canAccessProject($project);
    }

    public function update(User $user, Voucher $voucher): bool
    {
        $project = $voucher->relationLoaded('project')
            ? $voucher->project
            : $voucher->project()->first();

        return $project !== null && $this->create($user, $project);
    }

    public function send(User $user, Voucher $voucher): bool
    {
        return $this->update($user, $voucher);
    }
}
