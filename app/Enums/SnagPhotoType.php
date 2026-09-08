<?php

namespace App\Enums;

enum SnagPhotoType: string
{
    case Issue = 'issue';
    case Completion = 'completion';

    public function label(): string
    {
        return match ($this) {
            self::Issue => 'Constatering',
            self::Completion => 'Gereedfoto',
        };
    }

    public static function forStatus(SnagStatus $status): self
    {
        return $status->isFinished() ? self::Completion : self::Issue;
    }
}
