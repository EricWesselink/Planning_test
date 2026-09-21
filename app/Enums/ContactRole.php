<?php

namespace App\Enums;

enum ContactRole: string
{
    public const CUSTOM = '__new';

    case Uitvoerder = 'uitvoerder';
    case Opdrachtgever = 'opdrachtgever';
    case Klant = 'klant';

    public function label(): string
    {
        return match ($this) {
            self::Uitvoerder => 'Uitvoerder',
            self::Opdrachtgever => 'Opdrachtgever',
            self::Klant => 'Klant',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labeled(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->label()])
            ->all();
    }

    public static function labelFor(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === self::CUSTOM) {
            return null;
        }

        return match ($value) {
            'aannemer' => 'Aannemer',
            default => self::tryFrom($value)?->label() ?? $value,
        };
    }
}
