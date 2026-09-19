<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveRequestPeriodChangedMail extends Mailable
{
    use SerializesModels;

    public function __construct(public LeaveRequest $leaveRequest)
    {
        $this->leaveRequest->loadMissing(['user', 'worker', 'periodAdjuster']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Periode van je vrij-aanvraag is aangepast',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.leave-requests.period-changed',
            text: 'mail.leave-requests.period-changed-text',
        );
    }
}
