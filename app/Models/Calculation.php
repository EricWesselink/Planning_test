<?php

namespace App\Models;

use App\Enums\CalculationStatus;
use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'name', 'client_name', 'project_name', 'dated_on', 'status', 'import_status', 'created_by', 'warnings',
])]
class Calculation extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'concept',
    ];

    protected function casts(): array
    {
        return [
            'dated_on' => 'date',
            'status' => CalculationStatus::class,
            'import_status' => ImportStatus::class,
            'warnings' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function drawings(): HasMany
    {
        return $this->hasMany(CalculationDrawing::class)->orderBy('id');
    }

    public function workbooks(): HasMany
    {
        return $this->hasMany(CalculationWorkbook::class)->orderBy('id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CalculationLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return Collection<int, CalculationLine>
     */
    public function orderedLines(): Collection
    {
        return $this->lines;
    }

    public function isImporting(): bool
    {
        $status = $this->import_status ?? ImportStatus::Ready;

        return $status === ImportStatus::Pending || $status === ImportStatus::Processing;
    }

    public function importIsFinished(): bool
    {
        return ($this->import_status ?? ImportStatus::Ready) === ImportStatus::Ready;
    }

    public function isImportingFiles(): bool
    {
        $this->loadMissing(['drawings', 'workbooks']);

        return $this->drawings->contains(
            fn (CalculationDrawing $drawing): bool => ($drawing->import_status ?? ImportStatus::Ready)->isActive(),
        ) || $this->workbooks->contains(
            fn (CalculationWorkbook $workbook): bool => ($workbook->import_status ?? ImportStatus::Ready)->isActive(),
        );
    }
}
