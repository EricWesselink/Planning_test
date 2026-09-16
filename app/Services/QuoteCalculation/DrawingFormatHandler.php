<?php

namespace App\Services\QuoteCalculation;

interface DrawingFormatHandler
{
    public function name(): string;

    public function score(string $text): int;

    /**
     * @return array{
     *     rooms: list<array{
     *         room_number: ?string,
     *         room_name: ?string,
     *         square_meters: ?float,
     *         floor_code: ?string,
     *         plinth_code: ?string,
     *         plinth_meters: ?float,
     *         plinth_source: string,
     *         extra_codes: list<string>,
     *         note: ?string,
     *         needs_review: bool
     *     }>,
     *     legend: list<array{code: string, product: string, kind: string}>,
     *     warnings: list<string>
     * }
     */
    public function parse(string $text): array;
}
