<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'calculation_id', 'original_filename', 'file_path', 'mime_type', 'file_size',
    'parse_engine', 'format_handler', 'legend', 'warnings',
])]
class CalculationDrawing extends Model
{
    protected function casts(): array
    {
        return [
            'legend' => 'array',
            'warnings' => 'array',
            'file_size' => 'integer',
        ];
    }

    public function calculation(): BelongsTo
    {
        return $this->belongsTo(Calculation::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CalculationLine::class);
    }

    public function existsOnDisk(): bool
    {
        return $this->file_path !== '' && Storage::disk('local')->exists($this->file_path);
    }
}
