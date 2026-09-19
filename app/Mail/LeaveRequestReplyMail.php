<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use App\Models\LeaveRequestMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveRequestReplyMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public LeaveRequest $leaveRequest,
        public LeaveRequestMessage $leaveMessage,
    ) {
        $this->leaveRequest->loadMissing(['user', 'worker']);
        $this->leaveMessage->loadMissing('user');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Antwoord op vrij-aanvraag '.$this->leaveRequest->workerName(),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.leave-requests.reply',
            text: 'mail.leave-requests.reply-text',
        );
    }
}
