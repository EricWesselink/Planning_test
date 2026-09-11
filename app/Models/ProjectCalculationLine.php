<?php

namespace App\Models;

use App\Enums\WorkUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id', 'project_document_id', 'work_item_id', 'row_number', 'source_hash',
    'source_filename', 'km', 'group_code', 'mu', 'article_number', 'production_description',
    'article_description', 'unit', 'quantity', 'hours', 'hourly_rate', 'labor_cost',
    'unit_cost', 'total_cost', 'is_labor', 'work_match_key', 'work_match_label',
    'match_status', 'naca_code', 'raw',
])]
class ProjectCalculationLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'hours' => 'decimal:4',
            'hourly_rate' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'is_labor' => 'boolean',
            'raw' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ProjectDocument::class, 'project_document_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function description(): string
    {
        $production = trim((string) $this->production_description);
        $article = trim((string) $this->article_description);
        if ($production !== '' && $article !== '' && strcasecmp($production, $article) !== 0) {
            return $production;
        }

        return $production !== '' ? $production : $article;
    }

    public function isMatched(): bool
    {
        return in_array($this->match_status, ['matched', 'warning'], true) && filled($this->work_match_label);
    }

    public function usesSquareMeters(): bool
    {
        return $this->normalizedUnit() === WorkUnit::SquareMeter;
    }

    public function normalizedUnit(): ?WorkUnit
    {
        $unit = rtrim(mb_strtolower(trim((string) $this->unit)), '.');

        return match ($unit) {
            'm2', 'm²', 'm 2' => WorkUnit::SquareMeter,
            'm1', 'm¹', 'lm', 'm' => WorkUnit::LinearMeter,
            'st', 'stk', 'stuk', 'stuks', 'pcs' => WorkUnit::Pieces,
            'uur', 'uren', 'u', 'hour', 'hours', 'hrs' => WorkUnit::Hours,
            default => null,
        };
    }
}
