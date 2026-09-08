<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class PlanningHours
{
    public const WORKDAY_HOURS = 8;

    public const SNAP_HOURS = 2;

    public const DAY_START = '08:00';

    public const DAY_END = '16:00';

    public static function snapHours(float|int $hours): int
    {
        $snapped = (int) round(((float) $hours) / self::SNAP_HOURS) * self::SNAP_HOURS;

        return max(self::SNAP_HOURS, min(self::WORKDAY_HOURS, $snapped));
    }

    public static function normalizeTime(?string $time, string $fallback = self::DAY_START): string
    {
        $time = trim((string) $time);
        if ($time === '') {
            return strlen($fallback) === 5 ? $fallback.':00' : $fallback;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $match) !== 1) {
            return strlen($fallback) === 5 ? $fallback.':00' : $fallback;
        }

        return sprintf('%02d:%02d:00', min(23, (int) $match[1]), min(59, (int) $match[2]));
    }

    public static function formatTime(?string $time, string $fallback = self::DAY_START): string
    {
        return substr(self::normalizeTime($time, $fallback), 0, 5);
    }

    public static function hoursBetween(string $start, string $end): float
    {
        $startAt = Carbon::parse('2000-01-01 '.self::normalizeTime($start, self::DAY_START));
        $endAt = Carbon::parse('2000-01-01 '.self::normalizeTime($end, self::DAY_END));

        return max(0.0, ($endAt->timestamp - $startAt->timestamp) / 3600);
    }

    public static function fractionFromTime(?string $time): float
    {
        $hours = self::hoursBetween(self::DAY_START, self::normalizeTime($time, self::DAY_START));

        return max(0.0, min(1.0, $hours / self::WORKDAY_HOURS));
    }

    public static function timeFromFraction(float $fraction): string
    {
        $fraction = max(0.0, min(1.0, $fraction));
        $minutes = (int) round($fraction * self::WORKDAY_HOURS * 60);
        $start = Carbon::parse('2000-01-01 '.self::DAY_START.':00')->addMinutes($minutes);

        return $start->format('H:i:s');
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    public static function timesFromHours(float|int $hours, string $slot = 'morning'): array
    {
        $hours = self::snapHours($hours);
        if ($hours >= self::WORKDAY_HOURS || $slot === 'full') {
            return [self::DAY_START.':00', self::DAY_END.':00', self::WORKDAY_HOURS];
        }

        if ($slot === 'afternoon') {
            $end = Carbon::parse('2000-01-01 '.self::DAY_END.':00');
            $start = $end->copy()->subHours($hours);

            return [$start->format('H:i:s'), $end->format('H:i:s'), $hours];
        }

        $start = Carbon::parse('2000-01-01 '.self::DAY_START.':00');
        $end = $start->copy()->addHours($hours);

        return [$start->format('H:i:s'), $end->format('H:i:s'), $hours];
    }

    /**
     * @return array{start_time: string, end_time: string, hours: int}
     */
    public static function resolve(mixed $hours, mixed $slot, mixed $startTime, mixed $endTime): array
    {
        $slotName = is_string($slot) && $slot !== '' ? $slot : null;
        $hasTimes = is_string($startTime) && trim($startTime) !== '' && is_string($endTime) && trim($endTime) !== '';

        if ($hasTimes) {
            $start = self::normalizeTime($startTime, self::DAY_START);
            $end = self::normalizeTime($endTime, self::DAY_END);
            $duration = self::hoursBetween($start, $end);
            if ($duration < self::SNAP_HOURS) {
                [$start, $end, $duration] = self::timesFromHours(self::SNAP_HOURS, $slotName ?: 'morning');
            }

            return [
                'start_time' => $start,
                'end_time' => $end,
                'hours' => (int) round($duration),
            ];
        }

        if ($slotName === 'full') {
            $hoursValue = self::WORKDAY_HOURS;
        } elseif ($slotName === 'morning' || $slotName === 'afternoon') {
            $hoursValue = $hours !== null && $hours !== '' ? (float) $hours : 4.0;
        } else {
            $hoursValue = $hours !== null && $hours !== '' ? (float) $hours : self::WORKDAY_HOURS;
        }

        [$start, $end, $snapped] = self::timesFromHours($hoursValue, $slotName ?: 'morning');

        return [
            'start_time' => $start,
            'end_time' => $end,
            'hours' => $snapped,
        ];
    }

    public static function hoursOnDate(
        CarbonInterface $date,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        string $startTime,
        string $endTime,
    ): float {
        $interval = self::intervalOnDate($date, $startDate, $endDate, $startTime, $endTime);

        return $interval === null
            ? 0.0
            : max(0.0, ($interval[1]->timestamp - $interval[0]->timestamp) / 3600);
    }

    public static function totalHours(
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        string $startTime,
        string $endTime,
    ): float {
        $total = 0.0;
        $day = $startDate->copy()->startOfDay();
        $last = $endDate->copy()->startOfDay();
        while ($day->lte($last)) {
            $total += self::hoursOnDate($day, $startDate, $endDate, $startTime, $endTime);
            $day->addDay();
        }

        return $total;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function intervalOnDate(
        CarbonInterface $date,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        string $startTime,
        string $endTime,
    ): ?array {
        $day = $date->copy()->startOfDay();
        if ($day->lt($startDate->copy()->startOfDay()) || $day->gt($endDate->copy()->startOfDay())) {
            return null;
        }

        $isFirst = $day->isSameDay($startDate);
        $isLast = $day->isSameDay($endDate);
        $from = $isFirst ? self::normalizeTime($startTime, self::DAY_START) : self::DAY_START.':00';
        $to = $isLast ? self::normalizeTime($endTime, self::DAY_END) : self::DAY_END.':00';
        $start = Carbon::parse($day->toDateString().' '.$from);
        $end = Carbon::parse($day->toDateString().' '.$to);

        return $end->gt($start) ? [$start, $end] : null;
    }

    public static function intervalsOverlap(
        CarbonInterface $aStart,
        CarbonInterface $aEnd,
        CarbonInterface $bStart,
        CarbonInterface $bEnd,
    ): bool {
        return $aStart->lt($bEnd) && $bStart->lt($aEnd);
    }

    public static function hoursLabel(float|int $hours): string
    {
        $number = (float) $hours;
        $text = fmod($number, 1.0) === 0.0
            ? (string) (int) $number
            : rtrim(rtrim(number_format($number, 1, ',', ''), '0'), ',');

        return $text.'u';
    }

    /**
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $intervals
     */
    public static function uniqueHours(array $intervals): float
    {
        $ranges = [];
        foreach ($intervals as $interval) {
            $ranges[] = [$interval[0]->timestamp, $interval[1]->timestamp];
        }
        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $merged = [];
        foreach ($ranges as $range) {
            $last = $merged === [] ? null : count($merged) - 1;
            if ($last === null || $range[0] > $merged[$last][1]) {
                $merged[] = $range;

                continue;
            }

            $merged[$last][1] = max($merged[$last][1], $range[1]);
        }

        $seconds = 0;
        foreach ($merged as $range) {
            $seconds += max(0, $range[1] - $range[0]);
        }

        return min(self::WORKDAY_HOURS, $seconds / 3600);
    }

    public static function manDaysFromHours(float $hours): float
    {
        return round(min(self::WORKDAY_HOURS, max(0.0, $hours)) / self::WORKDAY_HOURS, 4);
    }

    public static function manDaysLabel(float $manDays): string
    {
        $value = round($manDays, 2);
        if (abs($value - (int) round($value)) < 0.001) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }
}
