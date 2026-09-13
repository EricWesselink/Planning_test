<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkerPlanningInviteMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public Worker $worker,
        public User $account,
        public string $loginUrl,
        public string $temporaryPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Uitnodiging planning · '.$this->worker->planName(),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.workers.planning-invite',
            text: 'mail.workers.planning-invite-text',
        );
    }
}
