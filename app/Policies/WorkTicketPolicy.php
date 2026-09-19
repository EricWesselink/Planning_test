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
        if (! $user->canViewWorkTickets()) {
            return false;
        }

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
        if (! $user->canCreateWorkTickets()) {
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
        if (! $user->canUpdateWorkTickets()) {
            return false;
        }

        $assignment = $workTicket->assignment;
        if ($assignment === null) {
            return $this->view($user, $workTicket);
        }

        $project = $assignment->relationLoaded('project')
            ? $assignment->project
            : $assignment->project()->first();

        return $project !== null && $user->canAccessProject($project);
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
            && $this->view($user, $workTicket)
            && $this->mayRecordHoursAsVakman($user, $workTicket);
    }

    private function mayRecordHoursAsVakman(User $user, WorkTicket $workTicket): bool
    {
        $worker = $workTicket->relationLoaded('worker')
            ? $workTicket->worker
            : $workTicket->worker()->first();

        if ($worker?->employment_type?->isExternal() ?? false) {
            return $user->scheduledWorkerId() === (int) $workTicket->worker_id;
        }

        return $this->isWorkTicketResponsible($user, $workTicket);
    }

    private function isWorkTicketResponsible(User $user, WorkTicket $workTicket): bool
    {
        $assignment = $workTicket->relationLoaded('assignment')
            ? $workTicket->assignment
            : $workTicket->assignment()->with('crewMembers')->first();

        if ($assignment === null) {
            return $user->scheduledWorkerId() === (int) $workTicket->worker_id;
        }

        return $assignment->isWorkTicketResponsible($user);
    }
}
