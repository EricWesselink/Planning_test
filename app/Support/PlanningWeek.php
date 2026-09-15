<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\Validator;

class PlanningWeek
{
    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'start_year' => ['nullable', 'integer', 'min:'.self::MIN_YEAR, 'max:'.self::MAX_YEAR],
            'start_week' => ['nullable', 'integer', 'min:1', 'max:53', 'required_with:start_year'],
            'klaar_year' => ['nullable', 'integer', 'min:'.self::MIN_YEAR, 'max:'.self::MAX_YEAR],
            'klaar_week' => ['nullable', 'integer', 'min:1', 'max:53', 'required_with:klaar_year'],
            'start_date' => ['nullable', 'date'],
            'klaar_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'start_week.required_with' => 'Vul een weeknummer in bij start werk.',
            'klaar_week.required_with' => 'Vul een weeknummer in bij klaar werk.',
        ];
    }

    public static function validateOrder(Validator $validator, ?CarbonInterface $currentStart = null, ?CarbonInterface $currentEnd = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $data = $validator->getData();
        [$startYear, $startWeek] = self::normalizedWeek(
            self::optionalInt($data['start_year'] ?? null),
            self::optionalInt($data['start_week'] ?? null),
        );
        [$klaarYear, $klaarWeek] = self::normalizedWeek(
            self::optionalInt($data['klaar_year'] ?? null),
            self::optionalInt($data['klaar_week'] ?? null),
        );

        if ($startYear !== null && $startWeek !== null && ! self::weekExists($startYear, $startWeek)) {
            $validator->errors()->add('start_week', 'Dit weeknummer bestaat niet in '.$startYear.'.');
        }

        if ($klaarYear !== null && $klaarWeek !== null && ! self::weekExists($klaarYear, $klaarWeek)) {
            $validator->errors()->add('klaar_week', 'Dit weeknummer bestaat niet in '.$klaarYear.'.');
        }

        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $dates = self::resolve($data, $currentStart, $currentEnd);
        if ($dates['start'] !== null && $dates['end'] !== null && $dates['end'] < $dates['start']) {
            $field = filled($data['klaar_date'] ?? null) ? 'klaar_date' : 'klaar_week';
            $validator->errors()->add($field, 'Klaar werk moet op dezelfde dag of later vallen dan start werk.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{start: ?string, end: ?string}
     */
    public static function fromInput(array $data): array
    {
        [$startYear, $startWeek] = self::normalizedWeek(
            self::optionalInt($data['start_year'] ?? null),
            self::optionalInt($data['start_week'] ?? null),
        );
        [$klaarYear, $klaarWeek] = self::normalizedWeek(
            self::optionalInt($data['klaar_year'] ?? null),
            self::optionalInt($data['klaar_week'] ?? null),
        );

        return [
            'start' => self::alignedDate(
                $startYear,
                $startWeek,
                self::optionalDate($data['start_date'] ?? null),
                endOfWeek: false,
            ),
            'end' => self::alignedDate(
                $klaarYear,
                $klaarWeek,
                self::optionalDate($data['klaar_date'] ?? null),
                endOfWeek: true,
            ),
        ];
    }

    /**
     * Datum en week horen bij elkaar: valt de datum in de opgegeven week, dan blijft die datum.
     * Anders winnen gewijzigde weekvelden, daarna de datum, daarna de huidige waarde.
     *
     * @param  array<string, mixed>  $data
     * @return array{start: ?string, end: ?string}
     */
    public static function resolve(array $data, ?CarbonInterface $currentStart = null, ?CarbonInterface $currentEnd = null): array
    {
        return [
            'start' => self::resolveSide(
                self::optionalInt($data['start_year'] ?? null),
                self::optionalInt($data['start_week'] ?? null),
                self::optionalDate($data['start_date'] ?? null),
                $currentStart,
                endOfWeek: false,
            ),
            'end' => self::resolveSide(
                self::optionalInt($data['klaar_year'] ?? null),
                self::optionalInt($data['klaar_week'] ?? null),
                self::optionalDate($data['klaar_date'] ?? null),
                $currentEnd,
                endOfWeek: true,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyTo(array $data, ?string $fallbackStart = null): array
    {
        $dates = self::fromInput($data);
        $fallbackStart = filled($fallbackStart) ? $fallbackStart : null;
        $data['planned_start_date'] = $dates['start'] ?? $fallbackStart ?? ($data['planned_start_date'] ?? null);
        $data['planned_end_date'] = $dates['end'] ?? ($data['planned_end_date'] ?? null);

        return $data;
    }

    public static function monday(?int $year, ?int $week): ?Carbon
    {
        if ($year === null || $week === null || ! self::weekExists($year, $week)) {
            return null;
        }

        return Carbon::now()->setISODate($year, $week, Carbon::MONDAY)->startOfDay();
    }

    public static function friday(?int $year, ?int $week): ?Carbon
    {
        if ($year === null || $week === null || ! self::weekExists($year, $week)) {
            return null;
        }

        return Carbon::now()->setISODate($year, $week, Carbon::FRIDAY)->startOfDay();
    }

    public static function saturday(?int $year, ?int $week): ?Carbon
    {
        if ($year === null || $week === null || ! self::weekExists($year, $week)) {
            return null;
        }

        return Carbon::now()->setISODate($year, $week, Carbon::SATURDAY)->startOfDay();
    }

    /**
     * @return array{start: Carbon, end: Carbon}|null
     */
    public static function assignmentRange(int $year, int $fromWeek, int $toWeek): ?array
    {
        $start = self::monday($year, $fromWeek);
        $end = self::friday($year, $toWeek);
        if ($start === null || $end === null || $end->lt($start)) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    public static function year(?CarbonInterface $date): ?int
    {
        return $date ? (int) $date->isoWeekYear() : null;
    }

    public static function number(?CarbonInterface $date): ?int
    {
        return $date ? (int) $date->isoWeek() : null;
    }

    public static function label(?CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date->isoWeekYear().' · week '.$date->isoWeek();
    }

    public static function weekExists(int $year, int $week): bool
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR || $week < 1 || $week > 53) {
            return false;
        }

        $maxWeek = (int) Carbon::create($year, 12, 28)->isoWeeksInYear();

        return $week <= $maxWeek;
    }

    public static function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    public static function optionalDate(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    public static function normalizedWeek(?int $year, ?int $week): array
    {
        if ($week === null) {
            return [null, null];
        }

        return [$year ?? (int) now()->isoWeekYear(), $week];
    }

    private static function alignedDate(?int $year, ?int $week, ?string $date, bool $endOfWeek): ?string
    {
        $weekDate = $endOfWeek
            ? self::saturday($year, $week)?->toDateString()
            : self::monday($year, $week)?->toDateString();

        if (self::dateBelongsToWeek($date, $year, $week)) {
            return $date;
        }

        return $weekDate ?? $date;
    }

    private static function dateBelongsToWeek(?string $date, ?int $year, ?int $week): bool
    {
        if ($date === null || $year === null || $week === null) {
            return false;
        }

        $parsed = Carbon::parse($date);

        return (int) $parsed->isoWeekYear() === $year && (int) $parsed->isoWeek() === $week;
    }

    private static function resolveSide(
        ?int $year,
        ?int $week,
        ?string $date,
        ?CarbonInterface $current,
        bool $endOfWeek,
    ): ?string {
        [$year, $week] = self::normalizedWeek($year, $week);
        $weekDate = $endOfWeek
            ? self::saturday($year, $week)?->toDateString()
            : self::monday($year, $week)?->toDateString();
        $currentDate = $current?->toDateString();
        $weekChanged = $weekDate !== null && (
            $year !== self::year($current) || $week !== self::number($current)
        );
        $dateChanged = $date !== null && $date !== $currentDate;

        if (self::dateBelongsToWeek($date, $year, $week)) {
            return $date;
        }

        if ($weekChanged) {
            return $weekDate;
        }

        if ($dateChanged) {
            return $date;
        }

        if ($weekDate === null && $date === null) {
            return null;
        }

        return $currentDate ?? $weekDate ?? $date;
    }
}
