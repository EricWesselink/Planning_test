<?php

namespace App\Services\QuoteCalculation;

use App\Models\WorkbookColumnMemory;
use App\Support\DutchNumber;
use Illuminate\Support\Facades\Schema;

class WorkbookColumnGuesser
{
    /**
     * @var array<string, string>
     */
    private array $memories = [];

    /**
     * @var list<string>
     */
    public const ROLES = [
        'room_number',
        'room_name',
        'building',
        'floor_level',
        'floor_finish',
        'plinth_finish',
        'product',
        'quantity',
        'unit',
    ];

    /**
     * @param  array<string, string>  $memories
     */
    public function withMemories(array $memories): self
    {
        $this->memories = $memories;

        return $this;
    }

    public static function remembering(): self
    {
        $guesser = new self;
        if (Schema::hasTable('workbook_column_memories')) {
            $guesser->withMemories(WorkbookColumnMemory::rolesByHeader());
        }

        return $guesser;
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{
     *     header_row: int|null,
     *     skippable: bool,
     *     skip_reason: ?string,
     *     product_hint: ?string,
     *     labels: list<array{column: string, header: string, role: string, confidence: string}>,
     *     groups: list<array{columns: array<string, int>, confidence: array<string, string>, product_hint: ?string}>
     * }
     */
    public function guess(array $rows): array
    {
        $width = $this->width($rows);
        $roomColumns = $this->roomNumberColumns($rows, $width);
        $ranges = $this->columnRanges($roomColumns, $width);
        $groups = [];
        foreach ($ranges as $range) {
            $hint = $this->titleHint($rows, $range[0], min($range[1], $range[0] + 8));
            $group = $this->guessRange($rows, $range[0], $range[1], $hint);
            if ($group['columns'] !== []) {
                $groups[] = $group;
            }
        }

        $labels = [];
        foreach ($groups as $group) {
            foreach ($group['columns'] as $role => $index) {
                $header = $this->headerLabel($rows, $group['header_row'] ?? null, $index);
                $labels[] = [
                    'column' => $this->columnLetter($index),
                    'header' => $header !== '' ? $header : 'Kolom '.$this->columnLetter($index),
                    'role' => $role,
                    'confidence' => $group['confidence'][$role] ?? 'review',
                ];
            }
        }

        $sheetHint = $groups[0]['product_hint'] ?? null;
        $useful = $this->hasUsefulFloorOrPlinth($groups, $sheetHint, $rows);
        $skipReason = $useful ? null : $this->skipReason($rows, $sheetHint);

        return [
            'header_row' => $groups[0]['header_row'] ?? null,
            'skippable' => ! $useful,
            'skip_reason' => $skipReason,
            'product_hint' => $sheetHint,
            'labels' => $labels,
            'groups' => array_map(fn (array $group): array => [
                'columns' => $group['columns'],
                'confidence' => $group['confidence'],
                'product_hint' => $group['product_hint'],
                'header_row' => $group['header_row'],
            ], $groups),
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{columns: array<string, int>, confidence: array<string, string>, product_hint: ?string, header_row: int|null}
     */
    private function guessRange(array $rows, int $from, int $to, ?string $titleHint): array
    {
        $best = null;
        $bestScore = -1;
        $limit = min(25, count($rows));
        for ($header = -1; $header < $limit; $header++) {
            $candidate = $this->scoreCandidate($rows, $header, $from, $to, $titleHint);
            if ($candidate['score'] > $bestScore) {
                $bestScore = $candidate['score'];
                $best = $candidate;
            }
        }

        return $best ?? [
            'columns' => [],
            'confidence' => [],
            'product_hint' => $titleHint,
            'header_row' => null,
            'score' => 0,
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{columns: array<string, int>, confidence: array<string, string>, product_hint: ?string, header_row: int|null, score: int}
     */
    private function scoreCandidate(array $rows, int $header, int $from, int $to, ?string $titleHint): array
    {
        $assignments = [];
        $confidence = [];
        $score = 0;
        $used = [];

        foreach (self::ROLES as $role) {
            $pick = $this->bestColumnForRole($rows, $header, $from, $to, $role, $used);
            if ($pick === null) {
                continue;
            }
            $assignments[$role] = $pick['index'];
            $confidence[$role] = $pick['confidence'];
            $used[$pick['index']] = true;
            $score += $pick['score'];
        }

        $area = $this->computedAreaIndex($rows, $header, $from, $to, $assignments['room_number'] ?? null);
        if ($area !== null) {
            $assignments['quantity'] = $area;
            $confidence['quantity'] = 'certain';
            $score += 8;
        }

        $productHint = $titleHint;
        if (isset($assignments['floor_finish'])) {
            $sample = $this->sampleValues($rows, $header, $assignments['floor_finish']);
            $fromValues = $this->finishHint($sample);
            if ($fromValues !== null) {
                $productHint = $fromValues;
            }
        }

        return [
            'columns' => $assignments,
            'confidence' => $confidence,
            'product_hint' => $productHint,
            'header_row' => $header >= 0 ? $header : null,
            'score' => $score,
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<int, true>  $used
     * @return array{index: int, score: int, confidence: string}|null
     */
    private function bestColumnForRole(array $rows, int $header, int $from, int $to, string $role, array $used): ?array
    {
        $best = null;
        for ($index = $from; $index <= $to; $index++) {
            if (isset($used[$index])) {
                continue;
            }
            $headerText = $header >= 0 ? $this->cell($rows, $header, $index) : '';
            if ($role === 'quantity' && $this->isDimensionHeader($headerText)) {
                continue;
            }
            $values = $this->sampleValues($rows, $header, $index);
            if ($this->isRepeatedCodeColumn($values, $role)) {
                continue;
            }
            $headerPoints = $this->headerScore($headerText, $role);
            if ($role === 'quantity' && $headerPoints < 2) {
                continue;
            }
            if (in_array($role, ['floor_finish', 'plinth_finish'], true) && ! $this->finishAppearsOnRoomRows($rows, $header, $index)) {
                continue;
            }
            $contentPoints = $this->contentScore($values, $role);
            $memoryPoints = $this->memoryBoost($headerText, $role, $contentPoints);
            $points = $headerPoints + $contentPoints + $memoryPoints;
            if ($points < 3) {
                continue;
            }
            $certain = ($headerPoints >= 4 && $contentPoints >= 2) || $memoryPoints >= 6;
            if ($role !== 'quantity') {
                $certain = $certain || $contentPoints >= 4;
            }
            $confidence = $certain ? 'certain' : 'review';
            if ($best === null || $points > $best['score']) {
                $best = ['index' => $index, 'score' => $points, 'confidence' => $confidence];
            }
        }

        return $best;
    }

    private function headerScore(string $header, string $role): int
    {
        $normalized = $this->normalizeHeader($header);
        if ($normalized === '') {
            return 0;
        }

        foreach ($this->synonyms($role) as $synonym => $weight) {
            if ($normalized === $synonym || str_contains($normalized, $synonym)) {
                return $weight;
            }
        }

        return 0;
    }

    private function memoryBoost(string $header, string $role, int $contentScore): int
    {
        if ($contentScore < 2) {
            return 0;
        }
        $normalized = $this->normalizeHeader($header);
        if ($normalized === '' || ($this->memories[$normalized] ?? null) !== $role) {
            return 0;
        }

        return 6;
    }

    /**
     * @param  list<string>  $values
     */
    private function contentScore(array $values, string $role): int
    {
        if ($values === []) {
            return 0;
        }
        $hits = 0;
        foreach ($values as $value) {
            if ($this->valueLooksLike($value, $role)) {
                $hits++;
            }
        }

        $ratio = $hits / max(1, count($values));
        if ($ratio >= 0.6 && count($values) >= 2) {
            return 4;
        }
        if ($ratio >= 0.3) {
            return 2;
        }

        return $hits > 0 ? 1 : 0;
    }

    /**
     * A column that repeats one finish code is a product block, not a per-room finish column.
     *
     * @param  list<string>  $values
     */
    private function isRepeatedCodeColumn(array $values, string $role): bool
    {
        if (! in_array($role, ['floor_finish', 'plinth_finish'], true) || count($values) < 5) {
            return false;
        }
        $codes = [];
        foreach ($values as $value) {
            if (preg_match('/(?<![a-z0-9])((?:v|pl)\d{2}(?:\.[a-z0-9]+)?)/iu', $value, $match)) {
                $codes[mb_strtolower($match[1])] = true;
            }
        }

        return count($codes) === 1;
    }

    /**
     * A section title such as "PU gietvloer v05" is not a per-room finish column.
     *
     * @param  list<list<string>>  $rows
     */
    private function finishAppearsOnRoomRows(array $rows, int $header, int $index): bool
    {
        $start = $header >= 0 ? $header + 1 : 0;
        for ($row = $start, $count = count($rows); $row < $count && $row < $start + 40; $row++) {
            $value = $this->cell($rows, $row, $index);
            if (! preg_match('/(?<![a-z0-9])(?:v|pl)\d{2}/iu', $value)) {
                continue;
            }
            foreach ($rows[$row] ?? [] as $cell) {
                if ($this->looksLikeRoomNumber((string) $cell)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function valueLooksLike(string $value, string $role): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return match ($role) {
            'room_number' => $this->looksLikeRoomNumber($value),
            'room_name' => $this->looksLikeRoomName($value),
            'building' => (bool) preg_match('/^(gebouw|blok|vleugel|[a-z])\b/iu', $value),
            'floor_level' => (bool) preg_match('/^(bg|kelder|zolder|vliering|\d{1,2}e|verdieping(?:\s+\d+)?)$/iu', $value),
            'floor_finish' => (bool) preg_match('/(?<![a-z0-9])v\d{2}(?:\.[a-z0-9]+)?(?![a-z0-9])/iu', $value)
                || (bool) preg_match('/gietvloer|marmoleum|pvc|tapijt|linoleum|coating/iu', $value),
            'plinth_finish' => (bool) preg_match('/(?<![a-z0-9])pl\d{2}(?:\.[a-z0-9]+)?(?![a-z0-9])/iu', $value)
                || (bool) preg_match('/plint|holplint/iu', $value),
            'product' => mb_strlen($value) > 2
                && ! $this->looksLikeRoomNumber($value)
                && ! $this->looksLikeQuantity($value)
                && ! (bool) preg_match('/^(m2|m²|m1|m¹|lm|stuks|st|uren|u)$/iu', $value),
            'quantity' => $this->looksLikeQuantity($value),
            'unit' => (bool) preg_match('/^(m2|m²|m1|m¹|lm|stuks|st|uren|u)$/iu', $value),
            default => false,
        };
    }

    public function looksLikeRoomNumber(string $value): bool
    {
        $value = trim($value);

        return (bool) preg_match('/^(?:[A-Z]-\d{1,2}-\d{2}(?:[\/-]\d{2})*|\d{2}-\d{2}|0\d[.\-]\d{2})$/iu', $value);
    }

    private function looksLikeRoomName(string $value): bool
    {
        if ($this->looksLikeRoomNumber($value) || $this->looksLikeQuantity($value)) {
            return false;
        }
        if (preg_match('/(?<![a-z0-9])(?:v|pl)\d{2}/iu', $value)) {
            return false;
        }
        if (preg_match('/gietvloer|marmoleum|pvc|tapijt|linoleum|coating|plint|saus|behang/iu', $value)) {
            return false;
        }

        if (preg_match('/^(m2|m²|m1|m¹|lm|stuks|st|uren|u)$/iu', $value)) {
            return false;
        }

        return mb_strlen($value) <= 80 && ! is_numeric(str_replace([',', '.'], '', $value));
    }

    private function looksLikeQuantity(string $value): bool
    {
        if (preg_match('/totaal|som|subtotal/iu', $value) || $this->looksLikeRoomNumber($value)) {
            return false;
        }

        return DutchNumber::fromMachine($value) !== null;
    }

    /**
     * @return array<string, int>
     */
    private function synonyms(string $role): array
    {
        return match ($role) {
            'room_number' => [
                'ruimte nr' => 6,
                'ruimtenr' => 6,
                'ruimtenummer' => 6,
                'ruimte nummer' => 6,
                'room number' => 6,
                'room no' => 6,
                'vertreknr' => 5,
                'ruimtecode' => 5,
                'room code' => 5,
                'nr' => 3,
                'nummer' => 3,
            ],
            'room_name' => [
                'ruimtenaam' => 6,
                'ruimte naam' => 6,
                'room name' => 6,
                'vertrek' => 5,
                'functie' => 4,
                'ruimte' => 3,
                'naam' => 3,
            ],
            'building' => [
                'gebouw' => 6,
                'building' => 6,
                'vleugel' => 5,
                'blok' => 4,
            ],
            'floor_level' => [
                'verdieping' => 6,
                'etage' => 5,
                'niveau' => 4,
                'floor level' => 6,
            ],
            'floor_finish' => [
                'vloerafwerking' => 6,
                'vloer afwerking' => 6,
                'floor finish' => 6,
                'vloercode' => 5,
                'vloerproduct' => 5,
                'vloer' => 4,
            ],
            'plinth_finish' => [
                'plintafwerking' => 6,
                'plint afwerking' => 6,
                'plinth' => 5,
                'holplint' => 5,
                'plint' => 4,
            ],
            'product' => [
                'productomschrijving' => 6,
                'artikelomschrijving' => 6,
                'product' => 5,
                'materiaal' => 4,
                'artikel' => 4,
                'afwerking' => 3,
                'omschrijving' => 3,
            ],
            'quantity' => [
                'hoeveelheid' => 6,
                'oppervlakte' => 5,
                'aantal' => 4,
                'quantity' => 5,
                'qty' => 4,
                'm2' => 4,
                'totaal' => 3,
            ],
            'unit' => [
                'eenheid' => 6,
                'unit' => 5,
                'eenh' => 4,
                'eh' => 4,
            ],
            default => [],
        };
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private function sampleValues(array $rows, int $header, int $index): array
    {
        $start = $header >= 0 ? $header + 1 : 0;
        $values = [];
        for ($row = $start, $count = count($rows); $row < $count && count($values) < 40; $row++) {
            $value = trim($this->cell($rows, $row, $index));
            if ($value === '' || preg_match('/^totaal\b/iu', $value)) {
                continue;
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<int>
     */
    private function roomNumberColumns(array $rows, int $width): array
    {
        $found = [];
        for ($index = 0; $index < $width; $index++) {
            $values = $this->sampleValues($rows, -1, $index);
            if ($this->contentScore($values, 'room_number') >= 2) {
                $found[] = $index;
            }
        }

        return $found;
    }

    /**
     * @param  list<int>  $roomColumns
     * @return list<array{0: int, 1: int}>
     */
    private function columnRanges(array $roomColumns, int $width): array
    {
        if ($roomColumns === [] || count($roomColumns) === 1) {
            return [[0, max(0, $width - 1)]];
        }

        $ranges = [];
        foreach ($roomColumns as $i => $start) {
            $end = ($roomColumns[$i + 1] ?? $width) - 1;
            if ($end < $start) {
                $end = $start;
            }
            $ranges[] = [$start, $end];
        }

        return $ranges;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function computedAreaIndex(array $rows, int $header, int $from, int $to, ?int $roomColumn): ?int
    {
        $start = $header >= 0 ? $header + 1 : 0;
        $numeric = [];
        $trials = 0;
        for ($row = $start, $count = count($rows); $row < $count && $trials < 25; $row++) {
            if ($roomColumn !== null && ! $this->looksLikeRoomNumber($this->cell($rows, $row, $roomColumn))) {
                continue;
            }
            $values = [];
            for ($index = $from; $index <= $to; $index++) {
                $number = DutchNumber::fromMachine($this->cell($rows, $row, $index));
                if ($number !== null && $number > 0) {
                    $values[$index] = $number;
                }
            }
            if (count($values) < 3) {
                continue;
            }
            $numeric[] = $values;
            $trials++;
        }
        if (count($numeric) < 3) {
            return null;
        }

        $best = null;
        $bestHits = 0;
        for ($candidate = $from; $candidate <= $to; $candidate++) {
            if ($candidate === $roomColumn) {
                continue;
            }
            $hits = 0;
            $seen = 0;
            foreach ($numeric as $values) {
                $target = $values[$candidate] ?? null;
                if ($target === null || $target < 0.4) {
                    continue;
                }
                $seen++;
                if ($this->isProductOfOthers($target, $values, $candidate)) {
                    $hits++;
                }
            }
            if ($seen < 3 || $hits / $seen < 0.6 || $hits <= $bestHits) {
                continue;
            }
            $bestHits = $hits;
            $best = $candidate;
        }

        return $best;
    }

    /**
     * @param  array<int, float>  $values
     */
    private function isProductOfOthers(float $target, array $values, int $candidate): bool
    {
        $others = [];
        foreach ($values as $index => $number) {
            if ($index === $candidate || $number < 0.2 || $number > 80) {
                continue;
            }
            $others[] = $number;
        }
        $count = count($others);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pair = $others[$i] * $others[$j];
                if ($this->almostEqual($pair, $target)) {
                    return true;
                }
                for ($k = $j + 1; $k < $count; $k++) {
                    if ($this->almostEqual($pair * $others[$k], $target)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function almostEqual(float $left, float $right): bool
    {
        return abs($left - $right) <= max(0.02, abs($right) * 0.02);
    }

    private function isDimensionHeader(string $header): bool
    {
        $normalized = $this->normalizeHeader($header);
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match('/^(l|b|n|st|stuks)$/u', $normalized)
            || (bool) preg_match('/\b(lengte|breedte|width|length|diepte|dikte|aantal|maal|factor)\b/u', $normalized);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function titleHint(array $rows, int $from = 0, int $to = 0): ?string
    {
        $limit = min(8, count($rows));
        for ($i = 0; $i < $limit; $i++) {
            $end = $to > $from ? $to : count($rows[$i] ?? []) - 1;
            for ($index = $from; $index <= $end; $index++) {
                $text = $this->cell($rows, $i, $index);
                if ($this->finishHint([$text]) !== null) {
                    return $this->finishHint([$text]);
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $values
     */
    private function finishHint(array $values): ?string
    {
        foreach ($values as $value) {
            if (preg_match('/((?:v|pl)\d{2}(?:\.[a-z0-9]+)?)/iu', $value, $match)) {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @param  list<list<string>>  $rows
     */
    private function hasUsefulFloorOrPlinth(array $groups, ?string $titleHint, array $rows): bool
    {
        $blob = mb_strtolower(implode(' ', array_map(fn (array $row): string => implode(' ', $row), array_slice($rows, 0, 12))));
        $hasFinishCode = (bool) preg_match('/(?<![a-z0-9])(?:v|pl)\d{2}/iu', $blob.' '.($titleHint ?? ''));
        if (str_contains($blob, 'wand') && ! str_contains($blob, 'vloer') && ! $hasFinishCode) {
            return false;
        }

        foreach ($groups as $group) {
            $columns = $group['columns'] ?? [];
            if (! isset($columns['room_number'])) {
                continue;
            }
            if (isset($columns['floor_finish']) || isset($columns['plinth_finish']) || isset($columns['product']) || isset($columns['quantity'])) {
                return true;
            }
        }

        return $hasFinishCode && $groups !== [];
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function skipReason(array $rows, ?string $titleHint): string
    {
        $blob = mb_strtolower(implode(' ', array_map(fn (array $row): string => implode(' ', $row), array_slice($rows, 0, 12))));
        if (str_contains($blob, 'wand') && ! str_contains($blob, 'vloer') && ! preg_match('/(?<![a-z0-9])(?:v|pl)\d{2}/iu', $blob)) {
            return 'Dit tabblad lijkt wandafwerking te bevatten, geen vloer- of plintgegevens.';
        }
        if ($titleHint !== null && preg_match('/wand|tegelwerk|saus|behang/iu', $titleHint) && ! preg_match('/(?<![a-z0-9])(?:v|pl)\d{2}/iu', $titleHint)) {
            return 'Geen bruikbare vloer- of plintkolommen gevonden.';
        }

        return 'Geen betrouwbare ruimtenummers met vloer- of plintinformatie gevonden.';
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function headerLabel(array $rows, ?int $headerRow, int $index): string
    {
        if ($headerRow === null) {
            return '';
        }

        return $this->cell($rows, $headerRow, $index);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function cell(array $rows, int $row, int $index): string
    {
        return trim((string) ($rows[$row][$index] ?? ''));
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function width(array $rows): int
    {
        $width = 0;
        foreach (array_slice($rows, 0, 40) as $row) {
            $width = max($width, count($row));
        }

        return $width;
    }

    public function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    public function roleLabel(string $role): string
    {
        return match ($role) {
            'room_number' => 'Ruimtenummer',
            'room_name' => 'Ruimtenaam',
            'building' => 'Gebouw',
            'floor_level' => 'Verdieping',
            'floor_finish' => 'Vloerafwerking',
            'plinth_finish' => 'Plintafwerking',
            'product' => 'Product',
            'quantity' => 'Hoeveelheid',
            'unit' => 'Eenheid',
            default => $role,
        };
    }

    public function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['²', '¹', '.', '_'], ['2', '1', ' ', ' '], $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
