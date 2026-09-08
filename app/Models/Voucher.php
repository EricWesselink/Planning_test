<?php

namespace App\Models;

use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number', 'type', 'worker_id', 'project_id', 'parent_id', 'created_by',
    'issued_on', 'total_amount', 'notes',
])]
class Voucher extends Model
{
    protected function casts(): array
    {
        return [
            'type' => VoucherType::class,
            'issued_on' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VoucherLine::class)->orderBy('id');
    }

    public static function nextNumber(): string
    {
        $year = (int) now()->year;
        $prefix = 'BON-'.$year.'-';
        $latest = static::query()
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('number');

        $seq = 1;
        if (is_string($latest) && preg_match('/^BON-\d+-(\d+)$/', $latest, $matches) === 1) {
            $seq = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public static function latestOpdracht(int $workerId, int $projectId): ?self
    {
        return static::query()
            ->with('lines')
            ->where('worker_id', $workerId)
            ->where('project_id', $projectId)
            ->where('type', VoucherType::Opdracht)
            ->orderByDesc('id')
            ->first();
    }
}
