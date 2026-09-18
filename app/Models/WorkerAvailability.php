<?php

namespace App\Models;

use App\Enums\AvailabilityKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['worker_id', 'crew_member_id', 'start_date', 'end_date', 'kind'])]
class WorkerAvailability extends Model
{
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'kind' => AvailabilityKind::class,
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }

    public function covers(CarbonInterface $day): bool
    {
        return $day->betweenIncluded($this->start_date, $this->end_date);
    }

    public function rangeLabel(): string
    {
        if ($this->start_date->isSameDay($this->end_date)) {
            return $this->start_date->translatedFormat('j M');
        }

        return $this->start_date->translatedFormat('j M').' – '.$this->end_date->translatedFormat('j M');
    }

    public function summaryLabel(): string
    {
        return $this->kind->shortLabel().' '.$this->rangeLabel();
    }
}
