<?php

namespace App\Models;

use App\Enums\DocumentMailStatus;
use App\Enums\DocumentMailType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'sender_name',
    'recipient',
    'cc',
    'subject',
    'body',
    'document_type',
    'project_id',
    'work_ticket_id',
    'attachment_filename',
    'status',
    'error_message',
    'sent_at',
])]
class DocumentMail extends Model
{
    protected function casts(): array
    {
        return [
            'document_type' => DocumentMailType::class,
            'status' => DocumentMailStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workTicket(): BelongsTo
    {
        return $this->belongsTo(WorkTicket::class);
    }

    public function listTitle(): string
    {
        $ticket = $this->relationLoaded('workTicket') ? $this->workTicket : null;
        if ($ticket !== null) {
            return $this->document_type->label().' '.$ticket->number;
        }

        return $this->document_type->label();
    }
}
