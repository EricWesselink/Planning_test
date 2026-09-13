<?php

namespace App\Enums;

enum FlooringSpecialty: string
{
    case PrimenEgaliseren = 'primen_egaliseren';
    case Pvc = 'pvc';
    case Vinyl = 'vinyl';
    case Linoleum = 'linoleum';
    case Tapijt = 'tapijt';
    case Gietvloer = 'gietvloer';
    case Coating = 'coating';
    case Entreemat = 'entreemat';
    case Plinten = 'plinten';
    case Inmeten = 'inmeten';
    case Montage = 'montage';
    case Reparatie = 'reparatie';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::PrimenEgaliseren => 'Primen & egaliseren',
            self::Pvc => 'PVC',
            self::Vinyl => 'Vinyl',
            self::Linoleum => 'Linoleum',
            self::Tapijt => 'Tapijt',
            self::Gietvloer => 'Gietvloer',
            self::Coating => 'Coating',
            self::Entreemat => 'Entreemat',
            self::Plinten => 'Plinten',
            self::Inmeten => 'Inmeten',
            self::Montage => 'Montage',
            self::Reparatie => 'Reparatie',
            self::Service => 'Service',
        };
    }

    public function hasQuantityRate(): bool
    {
        return match ($this) {
            self::Inmeten, self::Montage, self::Reparatie, self::Service => false,
            default => true,
        };
    }

    public static function tryFromLabel(string $label): ?self
    {
        $flat = mb_strtolower(trim($label));

        foreach (self::cases() as $case) {
            if ($flat === $case->value || $flat === mb_strtolower($case->label())) {
                return $case;
            }
        }

        return match (true) {
            str_contains($flat, 'dekvloer'), str_contains($flat, 'egal'), str_contains($flat, 'primen') => self::PrimenEgaliseren,
            str_contains($flat, 'marmoleum') => self::Linoleum,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function parts(string $stored): array
    {
        return preg_split('/\s*,\s*/', $stored, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  list<string|self>  $values
     * @return list<self>
     */
    public static function selectedFrom(array $values): array
    {
        $wanted = [];

        foreach ($values as $value) {
            $case = self::caseFrom($value);

            if ($case instanceof self) {
                $wanted[$case->value] = $case;
            }
        }

        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => isset($wanted[$case->value]),
        ));
    }

    /**
     * @param  list<self>  $cases
     */
    public static function labels(array $cases): ?string
    {
        $labels = array_map(fn (self $case): string => $case->label(), $cases);

        return $labels === [] ? null : implode(', ', $labels);
    }

    /**
     * @param  list<string|self>  $values
     */
    public static function storedLabels(array $values): ?string
    {
        $wanted = [];
        $custom = [];

        foreach ($values as $value) {
            $case = self::caseFrom($value);
            if ($case instanceof self) {
                $wanted[$case->value] = $case;

                continue;
            }

            $part = trim((string) $value);
            if ($part === '') {
                continue;
            }

            $key = mb_strtolower($part);
            if (! isset($custom[$key])) {
                $custom[$key] = $part;
            }
        }

        $labels = array_map(
            fn (self $case): string => $case->label(),
            array_values(array_filter(
                self::cases(),
                fn (self $case): bool => isset($wanted[$case->value]),
            )),
        );
        $labels = array_merge($labels, array_values($custom));

        return $labels === [] ? null : implode(', ', $labels);
    }

    /**
     * @return list<string>
     */
    public static function inputValues(string $stored): array
    {
        $values = [];

        foreach (self::parts($stored) as $part) {
            $case = self::caseFrom($part);
            $values[] = $case instanceof self ? $case->value : $part;
        }

        return $values;
    }

    /**
     * @param  iterable<int, mixed>  $stored
     * @return list<array{value: string, label: string}>
     */
    public static function catalog(iterable $stored = []): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = [
                'value' => $case->value,
                'label' => $case->label(),
            ];
        }

        foreach ($stored as $raw) {
            if (is_array($raw)) {
                continue;
            }

            foreach (self::parts((string) $raw) as $part) {
                $case = self::caseFrom($part);
                if ($case instanceof self) {
                    continue;
                }

                $key = mb_strtolower($part);
                if ($key === '' || isset($options[$key]) || isset($options[$part])) {
                    continue;
                }

                $options[$key] = [
                    'value' => $part,
                    'label' => $part,
                ];
            }
        }

        return array_values($options);
    }

    public static function caseFrom(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        $part = trim((string) $value);

        if ($part === '') {
            return null;
        }

        return self::tryFrom($part) ?? self::tryFromLabel($part);
    }

    public static function storedHas(string $stored, string $wanted): bool
    {
        $wanted = trim($wanted);
        if ($wanted === '') {
            return false;
        }

        $wantedCase = self::caseFrom($wanted);
        $wantedKey = mb_strtolower($wantedCase instanceof self ? $wantedCase->value : $wanted);

        foreach (self::inputValues($stored) as $have) {
            $haveCase = self::caseFrom($have);
            $haveKey = mb_strtolower($haveCase instanceof self ? $haveCase->value : $have);
            if ($haveKey === $wantedKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $labels
     * @return array{key: string, label: string}|null
     */
    public static function matchFromLabels(array $labels): ?array
    {
        foreach ($labels as $label) {
            $case = self::tryFromLabel((string) $label);
            if ($case instanceof self) {
                return [
                    'key' => $case->value,
                    'label' => $case->label(),
                ];
            }
        }

        return null;
    }
}
