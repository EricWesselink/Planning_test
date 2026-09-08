<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id', 'document_type', 'original_filename', 'file_path', 'mime_type',
    'file_size', 'parse_status', 'parsed_json', 'uploaded_by',
])]
class ProjectDocument extends Model
{
    protected function casts(): array
    {
        return [
            'parsed_json' => 'array',
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
            || str_ends_with(strtolower($this->original_filename), '.pdf');
    }
}
