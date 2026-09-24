<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentPdfMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $messageBody,
        public string $mailSubject,
        public string $filename,
        public string $pdfBinary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.documents.pdf',
            text: 'mail.documents.pdf-text',
            with: [
                'messageBody' => $this->messageBody,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdfBinary, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}
