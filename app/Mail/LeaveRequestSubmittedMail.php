<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveRequestSubmittedMail extends Mailable
{
    use SerializesModels;

    public function __construct(public LeaveRequest $leaveRequest)
    {
        $this->leaveRequest->loadMissing(['user', 'worker']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Vrij-aanvraag '.$this->leaveRequest->workerName().' – '.$this->leaveRequest->mailSubjectPeriod(),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.leave-requests.submitted',
            text: 'mail.leave-requests.submitted-text',
        );
    }
}
