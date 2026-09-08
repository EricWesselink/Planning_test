<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'snag_item_id', 'action', 'old_status', 'new_status', 'worker_id', 'note', 'created_by',
])]
class SnagHistory extends Model
{
    protected $table = 'snag_history';

    public function snag(): BelongsTo
    {
        return $this->belongsTo(SnagItem::class, 'snag_item_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
