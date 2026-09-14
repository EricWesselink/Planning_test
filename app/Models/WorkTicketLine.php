<?php

namespace App\Models;

use App\Enums\WorkUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'work_ticket_id', 'work_item_id', 'quantity', 'unit', 'unit_price', 'amount',
])]
class WorkTicketLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit' => WorkUnit::class,
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(WorkTicket::class, 'work_ticket_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }
}
