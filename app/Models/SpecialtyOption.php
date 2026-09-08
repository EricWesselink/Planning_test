<?php

namespace App\Models;

use App\Enums\FlooringSpecialty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class SpecialtyOption extends Model
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return static::query()->orderBy('name')->pluck('name')->all();
    }

    public static function remember(string $name): ?self
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, ',')) {
            return null;
        }

        if (FlooringSpecialty::caseFrom($name) instanceof FlooringSpecialty) {
            return null;
        }

        $match = static::query()->get()->first(
            fn (self $option): bool => mb_strtolower($option->name) === mb_strtolower($name)
        );

        return $match ?? static::query()->create(['name' => $name]);
    }

    /**
     * @param  list<mixed>  $values
     */
    public static function rememberMany(array $values): void
    {
        foreach ($values as $value) {
            static::remember((string) $value);
        }
    }
}
