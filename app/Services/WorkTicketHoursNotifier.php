<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkTicket;
use App\Notifications\WorkTicketHoursSubmittedNotification;

class WorkTicketHoursNotifier
{
    public function submitted(WorkTicket $ticket, ?User $except = null): void
    {
        $ticket->loadMissing(['creator', 'project.supervisor', 'worker']);

        $recipients = collect([$ticket->creator, $ticket->project?->supervisor])
            ->filter(fn (?User $user): bool => $user !== null && filled($user->email))
            ->reject(fn (User $user): bool => $except !== null && (int) $user->id === (int) $except->id)
            ->unique('id')
            ->values();

        foreach ($recipients as $user) {
            $user->notify(new WorkTicketHoursSubmittedNotification($ticket));
        }
    }
}
