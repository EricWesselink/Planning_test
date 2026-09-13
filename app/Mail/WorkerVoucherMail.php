<?php

namespace App\Mail;

use App\Enums\VoucherType;
use App\Models\Voucher;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkerVoucherMail extends Mailable
{
    use SerializesModels;

    public function __construct(public Voucher $voucher)
    {
        $this->voucher->loadMissing(['worker', 'project', 'lines.area', 'parent']);
    }

    public function envelope(): Envelope
    {
        $project = $this->voucher->project?->name ?? '';

        return new Envelope(
            subject: trim($this->voucher->type->label().' '.$this->voucher->number.' · '.$project),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.vouchers.worker',
            text: 'mail.vouchers.worker-text',
            with: [
                'attachToInvoice' => $this->voucher->type === VoucherType::Facturatie,
            ],
        );
    }
}
