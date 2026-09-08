<?php

namespace App\Enums;

enum SnagStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case ReportedDone = 'reported_done';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Assigned => 'Toegewezen',
            self::InProgress => 'In behandeling',
            self::ReportedDone => 'Gereed gemeld',
            self::Closed => 'Afgehandeld',
        };
    }

    public function boardLabel(): string
    {
        return $this->label();
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::Assigned => 'assigned',
            self::InProgress => 'progress',
            self::ReportedDone => 'wait',
            self::Closed => 'done',
        };
    }

    public function isOpenWork(): bool
    {
        return in_array($this, [self::Open, self::Assigned, self::InProgress], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::ReportedDone, self::Closed], true);
    }

    /** @return list<self> */
    public static function forFilter(?string $filter): array
    {
        return match ($filter) {
            'open' => [self::Open],
            'assigned', 'busy' => [self::Assigned],
            'in_progress', 'in_behandeling' => [self::InProgress],
            'reported_done', 'wait', 'reported' => [self::ReportedDone],
            'closed', 'done', 'approved' => [self::Closed],
            default => [],
        };
    }

    /** @return list<self> */
    public static function stillOpen(): array
    {
        return [self::Open, self::Assigned, self::InProgress, self::ReportedDone];
    }
}
