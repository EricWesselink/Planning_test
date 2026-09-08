<?php

namespace App\Models;

use App\Enums\WorkUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['worker_id', 'specialty', 'unit', 'unit_price'])]
class WorkerRate extends Model
{
    public const string HOURLY_SPECIALTY = 'uurtarief';

    protected function casts(): array
    {
        return [
            'unit' => WorkUnit::class,
            'unit_price' => 'decimal:2',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
