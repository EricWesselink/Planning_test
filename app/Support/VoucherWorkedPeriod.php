<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class VoucherWorkedPeriod
{
    /**
     * @return array<int, string>
     */
    public static function weekdayLabels(): array
    {
        return [
            1 => 'ma',
            2 => 'di',
            3 => 'wo',
            4 => 'do',
            5 => 'vr',
            6 => 'za',
            7 => 'zo',
        ];
    }

    /**
     * @param  list<mixed>  $weekdays
     * @return list<string>
     */
    public static function datesFromInput(?string $date, ?int $year, ?int $week, array $weekdays): array
    {
        $days = collect($weekdays)
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values();

        [$year, $week] = PlanningWeek::normalizedWeek($year, $week);
        $monday = PlanningWeek::monday($year, $week);

        if ($monday !== null && $days->isNotEmpty()) {
            return $days
                ->map(fn (int $weekday): string => $monday->copy()->isoWeekday($weekday)->toDateString())
                ->all();
        }

        $parsed = PlanningWeek::optionalDate($date);
        if ($parsed !== null) {
            $parsedDay = Carbon::parse($parsed)->startOfDay();
            if ($monday === null || ((int) $parsedDay->isoWeek() === $week && (int) $parsedDay->isoWeekYear() === $year)) {
                return [$parsed];
            }
        }

        if ($monday !== null) {
            return collect(range(0, 5))
                ->map(fn (int $offset): string => $monday->copy()->addDays($offset)->toDateString())
                ->all();
        }

        return $parsed !== null ? [$parsed] : [];
    }

    /**
     * @return array<int, string>
     */
    public static function weekdayDates(?int $year, ?int $week): array
    {
        [$year, $week] = PlanningWeek::normalizedWeek($year, $week);
        $monday = PlanningWeek::monday($year, $week);
        if ($monday === null) {
            return [];
        }

        return collect(range(1, 7))
            ->mapWithKeys(fn (int $weekday): array => [
                $weekday => $monday->copy()->isoWeekday($weekday)->toDateString(),
            ])
            ->all();
    }

    /**
     * @param  list<string>|null  $dates
     */
    public static function datesListLabel(?array $dates): string
    {
        $labels = self::weekdayLabels();

        return collect($dates ?? [])
            ->filter()
            ->map(function (mixed $date) use ($labels): string {
                $day = Carbon::parse((string) $date)->startOfDay();
                $name = $labels[(int) $day->isoWeekday()] ?? rtrim($day->translatedFormat('D'), '.');

                return $name.' '.$day->format('d-m-Y');
            })
            ->implode(', ');
    }

    /**
     * @param  list<string>|null  $dates
     * @return array{from: ?CarbonInterface, to: ?CarbonInterface}
     */
    public static function range(?array $dates): array
    {
        $clean = collect($dates ?? [])
            ->filter()
            ->map(fn (mixed $date): CarbonInterface => Carbon::parse((string) $date)->startOfDay())
            ->sortBy(fn (CarbonInterface $date): string => $date->toDateString())
            ->values();

        return [
            'from' => $clean->first(),
            'to' => $clean->last(),
        ];
    }

    /**
     * @param  list<string>|null  $dates
     */
    public static function label(?array $dates): string
    {
        $range = self::range($dates);
        if ($range['from'] === null) {
            return '';
        }

        return Format::dayAndWeek($range['from'], $range['to']);
    }

    /**
     * @param  list<string>|null  $dates
     * @return array{date: ?string, year: int, week: int, weekdays: list<int>, weekday_dates: array<int, string>}
     */
    public static function formValues(?array $dates, ?string $fallbackDate = null): array
    {
        $range = self::range($dates);
        $anchor = $range['from'] ?? ($fallbackDate ? Carbon::parse($fallbackDate)->startOfDay() : now()->startOfDay());
        $stored = collect($dates ?? [])->filter()->isNotEmpty();

        $weekdays = [];
        if ($stored && $range['from'] !== null && $range['to'] !== null) {
            $sameWeek = collect($dates)
                ->filter()
                ->every(function (mixed $date) use ($range): bool {
                    $day = Carbon::parse((string) $date);

                    return (int) $day->isoWeek() === (int) $range['from']->isoWeek()
                        && (int) $day->isoWeekYear() === (int) $range['from']->isoWeekYear();
                });
            if ($sameWeek) {
                $weekdays = collect($dates)
                    ->filter()
                    ->map(fn (mixed $date): int => (int) Carbon::parse((string) $date)->isoWeekday())
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                if ($weekdays === [1, 2, 3, 4, 5, 6]) {
                    $weekdays = [];
                }
            }
        }

        $singleDay = $stored && $range['from']?->equalTo($range['to'] ?? $range['from']);
        $year = (int) $anchor->isoWeekYear();
        $week = (int) $anchor->isoWeek();

        return [
            'date' => $singleDay ? $range['from']?->toDateString() : ($stored ? null : $anchor->toDateString()),
            'year' => $year,
            'week' => $week,
            'weekdays' => $weekdays,
            'weekday_dates' => self::weekdayDates($year, $week),
        ];
    }
}
