<?php

namespace App\Services\Meetstaat\Contracts;

interface MeetstaatFormatParser
{
    public function name(): string;

    public function matches(string $text): bool;

    /**
     * @return array<string, mixed>
     */
    public function parse(string $text): array;
}
