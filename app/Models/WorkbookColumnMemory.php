<?php

namespace App\Models;

use App\Services\QuoteCalculation\WorkbookColumnGuesser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'normalized_header', 'role', 'confirmations',
])]
class WorkbookColumnMemory extends Model
{
    /**
     * @return array<string, string>
     */
    public static function rolesByHeader(): array
    {
        return static::query()->pluck('role', 'normalized_header')->all();
    }

    public static function remember(string $header, string $role): void
    {
        $normalized = (new WorkbookColumnGuesser)->normalizeHeader($header);
        if ($normalized === '' || ! in_array($role, WorkbookColumnGuesser::ROLES, true)) {
            return;
        }

        $memory = static::query()->firstOrNew(['normalized_header' => $normalized]);
        if (! $memory->exists) {
            $memory->role = $role;
            $memory->confirmations = 1;
            $memory->save();

            return;
        }
        if ($memory->role === $role) {
            $memory->increment('confirmations');

            return;
        }
        if ((int) $memory->confirmations <= 1) {
            $memory->forceFill([
                'role' => $role,
                'confirmations' => 1,
            ])->save();
        }
    }
}
