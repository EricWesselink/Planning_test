<?php

namespace App\Notifications;

use App\Models\WorkTicket;
use App\Support\Format;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkTicketHoursSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(public WorkTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->ticket->loadMissing(['project', 'worker']);
        $project = $ticket->project;
        $hours = $ticket->worked_hours !== null
            ? Format::hours($ticket->worked_hours)
            : '—';

        return (new MailMessage)
            ->subject('Uren teruggestuurd · '.$ticket->number.' · '.($project?->name ?? 'project'))
            ->greeting($ticket->kind->label().' '.$ticket->number)
            ->line('De vakman heeft de uren teruggestuurd. Je kunt nu een bon maken om te factureren.')
            ->line('Opdrachtnemer: '.($ticket->worker?->displayName() ?: '—'))
            ->line('Project: '.($project?->displayTitle() ?? '—'))
            ->line('Periode: '.$ticket->dateRangeLabel())
            ->line('Uren: '.$hours)
            ->action('Open productie', route('production.index', [
                'worker_id' => $ticket->worker_id,
                'project_id' => $ticket->project_id,
                'from' => $ticket->start_date->toDateString(),
                'to' => $ticket->end_date->toDateString(),
            ]))
            ->line('Of open de '.$ticket->kind->label().': '.route('work-tickets.show', $ticket));
    }
}
