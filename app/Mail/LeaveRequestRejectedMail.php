<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveRequestRejectedMail extends Mailable
{
    use SerializesModels;

    public function __construct(public LeaveRequest $leaveRequest)
    {
        $this->leaveRequest->loadMissing(['user', 'worker']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Je vrij-aanvraag is afgewezen',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.leave-requests.rejected',
            text: 'mail.leave-requests.rejected-text',
        );
    }
}
