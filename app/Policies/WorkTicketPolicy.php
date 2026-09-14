<?php

namespace App\Policies;

use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Models\User;
use App\Models\WorkerAssignment;
use App\Models\WorkTicket;

class WorkTicketPolicy
{
    public function view(User $user, WorkTicket $workTicket): bool
    {
        $project = $workTicket->relationLoaded('project')
            ? $workTicket->project
            : $workTicket->project()->first();

        if ($project === null || ! $user->canAccessProject($project)) {
            return false;
        }

        $workerId = $user->scheduledWorkerId();

        return $workerId === null || (int) $workTicket->worker_id === $workerId;
    }

    public function create(User $user, ?WorkerAssignment $assignment = null): bool
    {
        if (! $user->canManagePlanning()) {
            return false;
        }

        if ($assignment === null) {
            return true;
        }

        $project = $assignment->relationLoaded('project')
            ? $assignment->project
            : $assignment->project()->first();

        return $project !== null && $user->canAccessProject($project);
    }

    public function update(User $user, WorkTicket $workTicket): bool
    {
        return $this->create($user, $workTicket->assignment);
    }

    public function delete(User $user, WorkTicket $workTicket): bool
    {
        return $this->update($user, $workTicket);
    }

    public function viewPrices(User $user, WorkTicket $workTicket): bool
    {
        if ($workTicket->kind !== WorkTicketKind::Opdrachtbon) {
            return false;
        }

        if (! $this->view($user, $workTicket)) {
            return false;
        }

        if ($user->canViewLaborCosts()) {
            return true;
        }

        return $user->isVakman()
            && $user->scheduledWorkerId() === (int) $workTicket->worker_id;
    }

    public function recordHours(User $user, WorkTicket $workTicket): bool
    {
        if ($workTicket->billing_method !== WorkTicketBilling::Hourly) {
            return false;
        }

        if ($this->update($user, $workTicket)) {
            return true;
        }

        return $user->isVakman()
            && $user->scheduledWorkerId() === (int) $workTicket->worker_id
            && $this->view($user, $workTicket);
    }
}
