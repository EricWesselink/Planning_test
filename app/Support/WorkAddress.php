<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class WorkAddress
{
    /**
     * @return array{work_address: ?string, address: ?string, postal_code: ?string, city: ?string}
     */
    public static function parse(mixed $line): array
    {
        $line = self::normalize($line);
        if ($line === null) {
            return self::emptyParts();
        }

        $postal = self::lastPostalCode($line);
        if ($postal !== null) {
            $street = self::blank(trim(substr($line, 0, $postal['offset']), " \t,"));
            $city = self::blank(trim(substr($line, $postal['offset'] + $postal['length'])));

            return self::pack($street, $postal['code'], $city);
        }

        if (preg_match('/^(.+?),\s*([^,]+)$/u', $line, $match) === 1) {
            $city = self::blank($match[2]);
            $street = self::blank($match[1]);
            if ($street !== null && $city !== null && self::looksLikePlace($city)) {
                return self::pack($street, null, $city);
            }
        }

        if (! preg_match('/\d/u', $line)) {
            return self::pack(null, null, $line);
        }

        return self::pack($line, null, null);
    }

    public static function compose(mixed $street, mixed $postal, mixed $city): ?string
    {
        $street = self::blank($street);
        $place = trim(implode(' ', array_filter([self::blank($postal), self::blank($city)])));
        $line = trim(implode(', ', array_filter([$street, $place !== '' ? $place : null])));

        return $line !== '' ? $line : null;
    }

    /**
     * Replace split address keys when a full work address was submitted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function overlay(array $data): array
    {
        if (! array_key_exists('work_address', $data)) {
            return $data;
        }

        $parsed = self::parse($data['work_address']);
        $data['work_address'] = $parsed['work_address'];
        $data['address'] = self::clip($parsed['address'], 255);
        $data['postal_code'] = self::clip($parsed['postal_code'], 16);
        $data['city'] = self::clip($parsed['city'], 255);
        $data['location'] = $data['city'];

        return $data;
    }

    public static function sync(Project $project): void
    {
        $fullDirty = $project->isDirty('work_address');
        $partsDirty = $project->isDirty('address') || $project->isDirty('postal_code') || $project->isDirty('city');
        if (! $fullDirty && ! $partsDirty) {
            return;
        }

        $kept = self::normalize($project->work_address);
        if ($fullDirty && $kept !== null) {
            $parsed = self::parse($kept);
            $project->work_address = self::clip($parsed['work_address'], 1000);
            $project->address = self::clip($parsed['address'], 255);
            $project->postal_code = self::clip($parsed['postal_code'], 16);
            $project->city = self::clip($parsed['city'], 255);

            return;
        }

        if (! $partsDirty) {
            $project->address = null;
            $project->postal_code = null;
            $project->city = null;
            $project->work_address = null;

            return;
        }

        $composed = self::compose($project->address, $project->postal_code, $project->city);
        if ($composed === null) {
            $explicitClear = $project->address === null
                && $project->postal_code === null
                && $project->city === null
                && $project->isDirty('address')
                && $project->isDirty('postal_code')
                && $project->isDirty('city');
            if ($explicitClear && ! $fullDirty) {
                $project->work_address = null;
            }

            return;
        }

        if ($fullDirty && $kept !== null && $kept !== $composed) {
            return;
        }

        $project->work_address = $composed;
    }

    public static function backfill(): void
    {
        DB::table('projects')
            ->where(function ($query): void {
                $query->whereNull('work_address')->orWhere('work_address', '');
            })
            ->orderBy('id')
            ->select(['id', 'address', 'postal_code', 'city'])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $line = self::compose($row->address, $row->postal_code, $row->city);
                    if ($line === null) {
                        continue;
                    }

                    DB::table('projects')
                        ->where('id', $row->id)
                        ->where(function ($query): void {
                            $query->whereNull('work_address')->orWhere('work_address', '');
                        })
                        ->update(['work_address' => $line]);
                }
            });
    }

    public static function clip(?string $value, int $limit): ?string
    {
        if ($value === null || mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit);
    }

    /**
     * @return array{work_address: null, address: null, postal_code: null, city: null}
     */
    private static function emptyParts(): array
    {
        return [
            'work_address' => null,
            'address' => null,
            'postal_code' => null,
            'city' => null,
        ];
    }

    /**
     * @return array{work_address: ?string, address: ?string, postal_code: ?string, city: ?string}
     */
    private static function pack(?string $street, ?string $postal, ?string $city): array
    {
        $street = self::blank($street);
        $postal = self::blank($postal);
        $city = self::blank($city);

        return [
            'work_address' => self::compose($street, $postal, $city),
            'address' => $street,
            'postal_code' => $postal,
            'city' => $city,
        ];
    }

    /**
     * @return array{code: string, offset: int, length: int}|null
     */
    private static function lastPostalCode(string $line): ?array
    {
        $count = preg_match_all('/(?<!\d)(\d{4})\s*([A-Za-z]{2})(?![A-Za-z0-9])/u', $line, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false || $count < 1) {
            return null;
        }

        $last = count($matches[0]) - 1;

        return [
            'code' => $matches[1][$last][0].' '.mb_strtoupper($matches[2][$last][0]),
            'offset' => $matches[0][$last][1],
            'length' => strlen($matches[0][$last][0]),
        ];
    }

    private static function looksLikePlace(string $city): bool
    {
        return preg_match('/^\d/u', $city) !== 1 && preg_match('/\p{L}{2}/u', $city) === 1;
    }

    private static function normalize(mixed $value): ?string
    {
        $value = str_replace("\u{00A0}", ' ', (string) $value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $value !== '' ? $value : null;
    }

    private static function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
