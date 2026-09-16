<?php

namespace App\Services\QuoteCalculation;

use App\Support\DutchNumber;

class GenericTextLayerHandler implements DrawingFormatHandler
{
    public const NAME = 'generic_text_layer';

    public function __construct(
        private PlinthLengthCalculator $plinthLengths = new PlinthLengthCalculator,
        private RoomFloorFinishes $floors = new RoomFloorFinishes,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function score(string $text): int
    {
        $score = 0;
        if ($this->legendFromText($text) !== []) {
            $score += 4;
        }
        if (preg_match('/\d+(?:[.,]\d+)?\s*m(?:²|2)\b/u', $text)) {
            $score += 2;
        }
        if ($this->hasRoomNumber($text)) {
            $score += 3;
        }

        return $score;
    }

    public function parse(string $text): array
    {
        $legend = $this->legendFromText($text);
        $legendMap = [];
        foreach ($legend as $entry) {
            $legendMap[$entry['code']] = $entry;
        }

        $rooms = $this->roomsFromText($text, $legendMap);
        $warnings = [];
        if ($rooms === [] && trim($text) !== '') {
            $warnings[] = 'Geen betrouwbare ruimtes gekoppeld. Vul de regels handmatig aan.';
        }
        if ($legend === []) {
            $warnings[] = 'Geen legenda/renvooi met productcodes gevonden. Producten kun je handmatig koppelen.';
        }

        return [
            'rooms' => $rooms,
            'legend' => $legend,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array{code: string, product: string, kind: string}>
     */
    public function legendFromText(string $text): array
    {
        $legend = [];
        $normalized = preg_replace('/\n\s*=\s*/', ' = ', $text) ?? $text;
        if (! preg_match_all(
            '/(?<![a-z0-9])((?:v|pl|w|p)\d{2}(?:\.[a-z0-9]+)?)\s*=\s*([^\n]+?)(?=\s+(?:v|pl|w|p)\d{2}(?:\.[a-z0-9]+)?\s*=|\n|$)/iu',
            $normalized,
            $matches,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        foreach ($matches as $match) {
            $code = $this->normalizeCode($match[1]);
            $product = $this->cleanProduct(str_replace(["\n", "\r"], ' ', $match[2]));
            if ($code === '' || $product === '' || $this->looksLikeMetaLegend($product)) {
                continue;
            }

            $legend[$code] = [
                'code' => $code,
                'product' => $product,
                'kind' => $this->codeKind($code),
            ];
        }

        return array_values($legend);
    }

    /**
     * @param  array<string, array{code: string, product: string, kind: string}>  $legendMap
     * @return list<array<string, mixed>>
     */
    private function roomsFromText(string $text, array $legendMap): array
    {
        $lines = $this->lines($text);
        $skipFrom = $this->skipIndex($lines);
        $preferBuildingCodes = (bool) preg_match('/\b[A-Z]-\d{2}-\d{2}\b/u', $text);
        $rooms = [];

        $count = count($lines);
        for ($index = 0; $index < $count; $index++) {
            if ($skipFrom !== null && $index >= $skipFrom) {
                break;
            }

            $line = $lines[$index];
            if ($this->isLegendLine($line) || $this->isNoiseLine($line)) {
                continue;
            }

            $number = $this->roomNumberIn($line, $preferBuildingCodes);
            if ($number === null) {
                continue;
            }

            $window = $this->window($lines, $index, $skipFrom, $preferBuildingCodes);
            if ($this->isLegendLine($window) || $this->isExampleBlock($window)) {
                continue;
            }

            $squareMeters = $this->firstSquareMeters($window);
            $name = $this->roomNameFrom($window, $number);
            $codes = $this->finishCodesFromBlock($lines, $index, $skipFrom, $preferBuildingCodes);
            $floorCodes = array_values(array_filter($codes, fn (string $code) => $this->codeKind($code) === 'floor'));
            $plinthCodes = array_values(array_filter($codes, fn (string $code) => $this->codeKind($code) === 'plinth'));
            $extra = array_values(array_filter(
                $codes,
                fn (string $code) => ! in_array($this->codeKind($code), ['floor', 'plinth'], true)
            ));

            $areas = $this->allSquareMeters($window);
            $squareMeters = $areas[0] ?? $squareMeters;
            $localAreas = [];
            foreach (array_slice($areas, 1) as $area) {
                if ($squareMeters !== null && $area >= $squareMeters * 0.9) {
                    continue;
                }
                $localAreas[] = $area;
            }
            $floorFinishes = $this->floors->split($floorCodes, $squareMeters, $localAreas);
            $floorCode = $floorFinishes[0]['code'] ?? (count($floorCodes) === 1 ? $floorCodes[0] : null);
            $plinthCode = count($plinthCodes) === 1 ? $plinthCodes[0] : null;
            $noteParts = [];
            $needsReview = $name === null || $squareMeters === null;
            if (count($floorCodes) > 1 && ! $this->floors->matchesRoomArea($floorFinishes, $squareMeters)) {
                $needsReview = true;
            }
            if (count($plinthCodes) > 1) {
                $needsReview = true;
                $noteParts[] = 'Meerdere plintcodes in dezelfde ruimte: '.implode(', ', $plinthCodes);
                $plinthCode = $plinthCodes[0];
            }
            if ($floorCode !== null && ! isset($legendMap[$floorCode])) {
                $needsReview = true;
            }
            foreach ($floorFinishes as $finish) {
                if (! isset($legendMap[$finish['code'] ?? ''])) {
                    $needsReview = true;
                }
            }
            if ($plinthCode !== null && ! isset($legendMap[$plinthCode])) {
                $needsReview = true;
            }
            if ($floorCode === null && $squareMeters !== null) {
                $needsReview = true;
            }

            $plinth = $this->plinthLength($window);
            $note = $noteParts === [] ? null : implode(' ', $noteParts);
            if (isset($plinth['trace']) && is_string($plinth['trace'])) {
                $note = trim(($note ?? '').' '.$plinth['trace']);
            }

            $key = mb_strtolower($number);
            $incoming = [
                'room_number' => $number,
                'room_name' => $name,
                'square_meters' => $squareMeters,
                'floor_code' => $floorCode,
                'floors' => $floorFinishes,
                'room_area' => $squareMeters,
                'plinth_code' => $plinthCode,
                'plinth_meters' => $plinth['meters'],
                'plinth_source' => $plinth['source'],
                'plinth_status' => $plinth['status'] ?? null,
                'plinth_trace' => isset($plinth['status']) ? json_encode($plinth, JSON_UNESCAPED_UNICODE) : null,
                'extra_codes' => $extra,
                'note' => $note,
                'needs_review' => $needsReview,
            ];

            if (! isset($rooms[$key])) {
                $rooms[$key] = $incoming;

                continue;
            }

            $rooms[$key] = $this->preferRicher($rooms[$key], $incoming);
        }

        return array_values($rooms);
    }

    /**
     * @param  list<string>  $lines
     */
    private function skipIndex(array $lines): ?int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/renvooi\s+ruimteaanduiding|disclaimers/iu', $line)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    /**
     * @param  list<string>  $lines
     */
    private function window(array $lines, int $index, ?int $skipFrom, bool $preferBuildingCodes): string
    {
        $parts = [];
        $start = max(0, $index - 2);
        $end = min(count($lines) - 1, $index + 3);
        for ($cursor = $start; $cursor <= $end; $cursor++) {
            if ($skipFrom !== null && $cursor >= $skipFrom) {
                break;
            }
            $line = $lines[$cursor];
            $here = $this->roomNumberIn($line, $preferBuildingCodes);
            $origin = $this->roomNumberIn($lines[$index], $preferBuildingCodes);
            if ($cursor !== $index && $here !== null && $here !== $origin) {
                if ($cursor > $index) {
                    break;
                }

                continue;
            }
            if ($this->isLegendLine($line)) {
                continue;
            }
            $parts[] = $line;
        }

        return trim(implode(' ', $parts));
    }

    private function roomNumberIn(string $line, bool $preferBuildingCodes = false): ?string
    {
        if (preg_match('/\b([A-Z]-\d{2}-\d{2})\b/u', $line, $match)) {
            return $match[1];
        }

        if ($preferBuildingCodes) {
            return null;
        }

        if (! preg_match('/(?<![0-9])(\d{1,2}[.\-]\d{1,3}[a-zA-Z]?)(?![0-9.\-])/u', $line, $match)) {
            return null;
        }

        $number = $match[1];
        $squareMeters = $this->firstSquareMeters($line);
        if ($squareMeters !== null && abs($squareMeters - (float) str_replace(',', '.', $number)) < 0.001) {
            return null;
        }
        if (preg_match('/'.preg_quote($number, '/').'\s*m(?:²|2)\b/u', $line)) {
            return null;
        }

        return $number;
    }

    private function hasRoomNumber(string $text): bool
    {
        return (bool) preg_match('/\b[A-Z]-\d{2}-\d{2}\b/u', $text)
            || (bool) preg_match('/(?<![0-9])\d{1,2}[.\-]\d{1,3}[a-zA-Z]?(?![0-9.\-])/u', $text);
    }

    private function firstSquareMeters(string $text): ?float
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/u', $text, $match)) {
            return null;
        }

        $value = DutchNumber::parse($match[1]);
        if ($value === null || $value <= 0 || $value > 20000) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<float>
     */
    private function allSquareMeters(string $text): array
    {
        if (! preg_match_all('/(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/u', $text, $matches)) {
            return [];
        }

        $values = [];
        foreach ($matches[1] as $raw) {
            $value = DutchNumber::parse((string) $raw);
            if ($value === null || $value <= 0 || $value > 20000) {
                continue;
            }
            $values[] = $value;
        }

        return $values;
    }

    private function roomNameFrom(string $window, string $number): ?string
    {
        $value = $window;
        $value = str_replace($number, ' ', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/(\d+(?:[.,]\d+)?)\s*m(?:²|2|¹|1)\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/(?<![a-z0-9])(?:v|pl|w|p)\d{2}(?:\.[a-z0-9]+)?(?![a-z0-9])/iu', ' ', $value) ?? $value;
        $value = preg_replace('/(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)/u', ' ', $value) ?? $value;
        $value = preg_replace('/\bomtrek\b.*$/iu', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value, " \t-–—:|,.");
        $value = preg_replace('/\b(renvooi|legenda|afwerking|plattegrond|begane|grond)\b/iu', '', $value) ?? $value;
        $value = trim($value);
        if ($value === '' || preg_match('/^[\d.,\s]+$/', $value) || mb_strlen($value) > 80) {
            return null;
        }

        return $value;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function finishCodesFromBlock(array $lines, int $index, ?int $skipFrom, bool $preferBuildingCodes): array
    {
        $codes = $this->finishCodesIn($lines[$index]);
        $codeOnlyRun = 0;
        $limit = min($index + 3, count($lines) - 1);

        for ($next = $index + 1; $next <= $limit; $next++) {
            if ($skipFrom !== null && $next >= $skipFrom) {
                break;
            }
            $line = $lines[$next];
            if ($this->isLegendLine($line) || $this->isNoiseLine($line)) {
                break;
            }
            if ($this->roomNumberIn($line, $preferBuildingCodes) !== null) {
                break;
            }
            if ($this->isCodeOnlyLine($line)) {
                $codeOnlyRun++;
                if ($codeOnlyRun >= 2 || count($this->finishCodesIn($line)) > 1) {
                    break;
                }
                if ($codeOnlyRun === 1 && $next + 1 <= $limit && $this->isCodeOnlyLine($lines[$next + 1])) {
                    break;
                }
                foreach ($this->finishCodesIn($line) as $code) {
                    $codes[] = $code;
                }

                continue;
            }

            $codeOnlyRun = 0;
            foreach ($this->finishCodesIn($line) as $code) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function isCodeOnlyLine(string $line): bool
    {
        $stripped = trim(preg_replace('/(?<![a-z0-9])(?:v|pl|w|p)\d{2}(?:\.[a-z0-9]+)?(?![a-z0-9])/iu', '', $line) ?? $line);

        return $stripped === '' && $this->finishCodesIn($line) !== [];
    }

    /**
     * @return list<string>
     */
    private function finishCodesIn(string $text): array
    {
        preg_match_all('/(?<![a-z0-9])((?:v|pl)\d{2}(?:\.[a-z0-9]+)?)(?![a-z0-9])/iu', $text, $matches);

        $codes = [];
        foreach ($matches[1] as $raw) {
            $code = $this->normalizeCode((string) $raw);
            if ($code !== '') {
                $codes[$code] = $code;
            }
        }

        return array_values($codes);
    }

    /**
     * @return array{meters: ?float, source: string, trace?: string, status?: string, gross?: ?float, doors?: list<float>, net?: ?float}
     */
    private function plinthLength(string $window): array
    {
        if (preg_match('/omtrek\s*[:=]?\s*(\d+(?:[.,]\d+)?)\s*m(?:¹|1)?\b/iu', $window, $match)) {
            $meters = DutchNumber::parse($match[1]);
            if ($meters !== null && $meters > 0 && $meters < 500) {
                return $this->plinthLengths->result($meters, [], $meters, true);
            }
        }

        if (preg_match('/plint(?:lengte)?\s*[:=]?\s*(\d+(?:[.,]\d+)?)\s*m(?:¹|1)?\b/iu', $window, $match)) {
            $meters = DutchNumber::parse($match[1]);
            if ($meters !== null && $meters > 0 && $meters < 500) {
                return ['meters' => $meters, 'source' => 'from_drawing'];
            }
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*m(?:¹|1)\b/u', $window, $match)) {
            $meters = DutchNumber::parse($match[1]);
            if ($meters !== null && $meters > 0 && $meters < 500) {
                return ['meters' => $meters, 'source' => 'from_drawing'];
            }
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)(?:\s*m)?/u', $window, $match)) {
            $width = DutchNumber::parse($match[1]);
            $length = DutchNumber::parse($match[2]);
            if ($width !== null && $length !== null && $width >= 0.4 && $length >= 0.4 && $width <= 80 && $length <= 80) {
                $gross = 2 * ($width + $length);

                return $this->plinthLengths->result($gross, [], $gross, true);
            }
        }

        return ['meters' => null, 'source' => 'review'];
    }

    private function isLegendLine(string $line): bool
    {
        return (bool) preg_match('/(?<![a-z0-9])(?:v|pl|w|p)\d{2}(?:\.[a-z0-9]+)?\s*=/iu', $line);
    }

    private function isExampleBlock(string $window): bool
    {
        return (bool) preg_match('/ruimte\s+(benaming|oppervlakte|volgnummer)|renvooi\s+ruimte/iu', $window);
    }

    private function isNoiseLine(string $line): bool
    {
        return (bool) preg_match('/^(renvooi|legenda|disclaimer|schaal|noord)\b/iu', $line);
    }

    private function looksLikeMetaLegend(string $product): bool
    {
        return (bool) preg_match('/wandafwerking|plafondafwerking|vloerafwerking|plintafwerking/iu', $product);
    }

    private function cleanProduct(string $value): string
    {
        $value = preg_replace('/\b(renvooi|legenda)\b.*$/iu', '', $value) ?? $value;
        $value = trim($value, " \t-–—:|");
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        if (mb_strlen($value) < 3 || mb_strlen($value) > 120) {
            return '';
        }

        return $value;
    }

    private function normalizeCode(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    private function codeKind(string $code): string
    {
        if (str_starts_with($code, 'pl')) {
            return 'plinth';
        }
        if (str_starts_with($code, 'v')) {
            return 'floor';
        }
        if (str_starts_with($code, 'w')) {
            return 'wall';
        }

        return 'ceiling';
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function preferRicher(array $current, array $incoming): array
    {
        if ($current['square_meters'] === null && $incoming['square_meters'] !== null) {
            $current['square_meters'] = $incoming['square_meters'];
        }
        if (($current['room_name'] === null || $current['room_name'] === '') && filled($incoming['room_name'])) {
            $current['room_name'] = $incoming['room_name'];
        }
        if ($current['floor_code'] === null && $incoming['floor_code'] !== null) {
            $current['floor_code'] = $incoming['floor_code'];
        }
        if (($current['floors'] ?? []) === [] && ($incoming['floors'] ?? []) !== []) {
            $current['floors'] = $incoming['floors'];
        } elseif (($incoming['floors'] ?? []) !== []) {
            $current['floors'] = $this->mergeFloors($current['floors'] ?? [], $incoming['floors']);
        }
        if (($current['room_area'] ?? null) === null && is_numeric($incoming['room_area'] ?? null)) {
            $current['room_area'] = $incoming['room_area'];
        }
        if ($current['plinth_code'] === null && $incoming['plinth_code'] !== null) {
            $current['plinth_code'] = $incoming['plinth_code'];
        }
        if ($current['plinth_meters'] === null && $incoming['plinth_meters'] !== null) {
            $current['plinth_meters'] = $incoming['plinth_meters'];
            $current['plinth_source'] = $incoming['plinth_source'];
            $current['plinth_status'] = $incoming['plinth_status'] ?? null;
            $current['plinth_trace'] = $incoming['plinth_trace'] ?? null;
            if (filled($incoming['note'] ?? null) && ! filled($current['note'] ?? null)) {
                $current['note'] = $incoming['note'];
            }
        }
        $current['needs_review'] = $current['needs_review'] || $incoming['needs_review'];

        return $current;
    }

    /**
     * @param  list<array{code: string, quantity: ?float, role: string}>  $current
     * @param  list<array{code: string, quantity: ?float, role: string}>  $incoming
     * @return list<array{code: string, quantity: ?float, role: string}>
     */
    private function mergeFloors(array $current, array $incoming): array
    {
        $byCode = [];
        foreach ($current as $finish) {
            $code = mb_strtolower((string) ($finish['code'] ?? ''));
            if ($code !== '') {
                $byCode[$code] = $finish;
            }
        }
        foreach ($incoming as $finish) {
            $code = mb_strtolower((string) ($finish['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (! isset($byCode[$code])) {
                $byCode[$code] = $finish;

                continue;
            }
            if (($byCode[$code]['quantity'] ?? null) === null && is_numeric($finish['quantity'] ?? null)) {
                $byCode[$code]['quantity'] = $finish['quantity'];
            }
        }

        return array_values($byCode);
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = preg_split("/\n/", $text) ?: [];

        return array_values(array_filter(array_map(function (string $line): string {
            $line = preg_replace('/[\x{E000}-\x{F8FF}]/u', '', $line) ?? $line;
            $line = str_replace("\xC2\xA0", ' ', $line);

            return trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);
        }, $lines)));
    }
}
