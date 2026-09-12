<?php

namespace App\Models;

use App\Enums\SnagPhotoType;
use App\Enums\SnagPriority;
use App\Enums\SnagStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'project_id', 'project_area_id', 'document_id', 'drawing_page', 'x', 'y', 'number',
    'public_token', 'public_token_expires_at', 'public_token_revoked_at', 'description',
    'assigned_worker_id', 'priority', 'due_date',
    'logged_on', 'status', 'created_by', 'completed_at', 'approved_at', 'closed_at', 'closed_by',
])]
class SnagItem extends Model
{
    protected static function booted(): void
    {
        static::creating(function (SnagItem $snag): void {
            $snag->public_token ??= Str::lower(Str::random(48));
            $snag->logged_on ??= now()->toDateString();
            $snag->public_token_expires_at ??= now()->addDays((int) config('snags.public_token_ttl_days', 30));
        });
    }

    protected function casts(): array
    {
        return [
            'drawing_page' => 'integer',
            'x' => 'float',
            'y' => 'float',
            'priority' => SnagPriority::class,
            'status' => SnagStatus::class,
            'due_date' => 'date',
            'logged_on' => 'date',
            'completed_at' => 'datetime',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
            'public_token_expires_at' => 'datetime',
            'public_token_revoked_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(ProjectArea::class, 'project_area_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ProjectDocument::class, 'document_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'assigned_worker_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SnagPhoto::class)->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(SnagHistory::class)->orderByDesc('id');
    }

    public function issuePhotos()
    {
        return $this->photos->where('photo_type', SnagPhotoType::Issue)->values();
    }

    public function completionPhotos()
    {
        return $this->photos->where('photo_type', SnagPhotoType::Completion)->values();
    }

    public function title(): string
    {
        $room = $this->area?->label();

        return $room
            ? 'Opleverpunt '.$this->number.' – '.$room
            : 'Opleverpunt #'.$this->number;
    }

    public function publicUrl(): string
    {
        return route('snags.public.show', $this->public_token);
    }

    public function publicPhotoUrl(SnagPhoto $photo): string
    {
        return route('snags.public.photo', [$this->public_token, $photo]);
    }

    public function publicAccessIsActive(): bool
    {
        if ($this->public_token === null || $this->public_token === '') {
            return false;
        }

        if ($this->public_token_revoked_at !== null) {
            return false;
        }

        return $this->public_token_expires_at === null || $this->public_token_expires_at->isFuture();
    }

    public function publicAccessAllowsChanges(): bool
    {
        return $this->publicAccessIsActive() && ! $this->status->isFinished();
    }
}
