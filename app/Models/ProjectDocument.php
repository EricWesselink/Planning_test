<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id', 'document_type', 'revision', 'is_current', 'original_filename', 'file_path', 'mime_type',
    'file_size', 'parse_status', 'parsed_json', 'uploaded_by',
])]
class ProjectDocument extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revision' => 1,
        'is_current' => true,
    ];

    protected function casts(): array
    {
        return [
            'parsed_json' => 'array',
            'revision' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf'
            || str_ends_with(strtolower((string) $this->original_filename), '.pdf');
    }

    public function drawingLabel(): string
    {
        $name = trim((string) $this->original_filename);
        $stripped = preg_replace('/\.pdf$/i', '', $name);

        return is_string($stripped) && $stripped !== '' ? $stripped : 'Tekening';
    }
}
