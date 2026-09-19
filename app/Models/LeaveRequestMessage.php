<?php

namespace App\Models;

use Database\Factories\LeaveRequestMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'leave_request_id',
    'user_id',
    'body',
    'is_system',
])]
class LeaveRequestMessage extends Model
{
    /** @use HasFactory<LeaveRequestMessageFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_system' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function authorLabel(): string
    {
        $name = $this->user?->name ?? 'Onbekend';
        if ($this->user?->isVakman()) {
            return $name;
        }

        $role = $this->user?->role?->label();

        return $role ? $name.' ('.$role.')' : $name;
    }
}
