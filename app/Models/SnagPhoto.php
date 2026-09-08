<?php

namespace App\Models;

use App\Enums\SnagPhotoType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['snag_item_id', 'file_path', 'original_filename', 'photo_type', 'uploaded_by'])]
class SnagPhoto extends Model
{
    protected function casts(): array
    {
        return [
            'photo_type' => SnagPhotoType::class,
        ];
    }

    public function snag(): BelongsTo
    {
        return $this->belongsTo(SnagItem::class, 'snag_item_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
