<?php

namespace App\Services\Meetstaat;

use App\Support\DutchNumber;

class FloorPlanParser
{
    public function __construct(
        private PdfTextExtractor $extractor,
        private ?DrawingColorMatcher $colors = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path, ?string $originalName = null): array
    {
        $extracted = $this->extractor->extractDrawing($path);
        $parsed = $this->blankParse();
        $parsed['engine'] = $extracted['engine'];

        try {
            $parsed = $this->colors()->enrich($path, $parsed, $originalName);
        } catch (\Throwable) {
        }

        if (($parsed['areas'] ?? []) === []) {
            $fromText = $this->parseText($extracted['text'], $originalName);
            $fromText['engine'] = $extracted['engine'];
            $fromText['debug_rooms'] = $parsed['debug_rooms'] ?? [];
            $fromText['legend'] = $parsed['legend'] ?? [];
            $fromText['works'] = ($parsed['works'] ?? []) !== [] ? $parsed['works'] : $fromText['works'];
            $fromText['color_engine'] = $parsed['color_engine'] ?? null;
            $fromText['sources'] = $parsed['sources'] ?? [];
            $parsed = $fromText;
        }

        $floors = [];
        foreach ($parsed['areas'] ?? [] as $area) {
            $floors[$area['floor'] ?? 'Onbekend'] = true;
        }
        $parsed['floors'] = array_keys($floors);
        $parsed['needs_ocr'] = $extracted['needs_ocr'] && ($parsed['areas'] ?? []) === [];
        if ($parsed['needs_ocr']) {
            $parsed['warnings'][] = 'Deze plattegrond heeft geen bruikbare tekstlaag. Ruimtes kun je handmatig aanvullen.';
        }

        $parsed['drawing_style'] = (new DrawingStyleAssessor)->assess(
            $parsed['areas'] ?? [],
            $parsed['legend'] ?? [],
        );

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseText(string $text, ?string $originalName = null): array
    {
        $lines = preg_split("/\n/", $text) ?: [];
        $floorHint = $this->floorFromFilename($originalName);
        $currentFloor = $floorHint ?? 'Onbekend';
        $areas = [];
        $uncertain = [];
        $warnings = [];

        $count = count($lines);
        for ($index = 0; $index < $count; $index++) {
            $line = $this->cleanLine($lines[$index]);
            if ($line === '') {
                continue;
            }

            $headerFloor = $this->floorFromHeader($line);
            if ($headerFloor !== null) {
                $currentFloor = $headerFloor;

                continue;
            }

            $parsed = $this->parseRoomLine($line);
            if ($parsed === null) {
                continue;
            }

            if ($parsed['room_name'] === '' || $parsed['square_meters'] === null) {
                $lookAhead = $this->lookAhead($lines, $index);
                if ($parsed['room_name'] === '' && $lookAhead['room_name'] !== '') {
                    $parsed['room_name'] = $lookAhead['room_name'];
                }
                if ($parsed['square_meters'] === null && $lookAhead['square_meters'] !== null) {
                    $parsed['square_meters'] = $lookAhead['square_meters'];
                }
            }

            if ($parsed['room_name'] === '' && $parsed['square_meters'] === null) {
                $uncertain[] = ['line' => $line, 'reason' => 'Ruimtenummer zonder betrouwbare naam of m².'];

                continue;
            }

            $number = $this->normalizeNumber((string) $parsed['room_number']);
            $key = mb_strtolower($currentFloor).'|'.$number;
            $needsReview = $parsed['room_name'] === '' || $parsed['square_meters'] === null;
            $incoming = [
                'key' => $key,
                'floor' => $currentFloor,
                'room_number' => $parsed['room_number'],
                'room_name' => $parsed['room_name'] !== '' ? $parsed['room_name'] : $parsed['room_number'],
                'square_meters' => $parsed['square_meters'],
                'tasks' => [],
                'source' => 'plattegrond',
                'needs_review' => $needsReview,
            ];

            if (! isset($areas[$key])) {
                $areas[$key] = $incoming;

                continue;
            }

            $areas[$key] = $this->preferRicher($areas[$key], $incoming);
        }

        $floors = [];
        foreach ($areas as $area) {
            $floors[$area['floor']] = true;
        }

        if ($areas === [] && trim($text) !== '') {
            $warnings[] = 'Geen betrouwbare ruimtes op de plattegrond gevonden. Je kunt ze handmatig toevoegen.';
        }

        $areaList = array_values($areas);

        return [
            'format' => 'floor_plan',
            'header' => [
                'customer_name' => null,
                'reference' => null,
                'project_name' => null,
                'project_number' => null,
                'date' => null,
            ],
            'works' => [],
            'areas' => $areaList,
            'floors' => array_keys($floors),
            'warnings' => $warnings,
            'uncertain' => $uncertain,
            'duplicates' => [],
            'needs_ocr' => false,
            'drawing_style' => (new DrawingStyleAssessor)->assess($areaList, []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blankParse(): array
    {
        return [
            'format' => 'floor_plan',
            'header' => [
                'customer_name' => null,
                'reference' => null,
                'project_name' => null,
                'project_number' => null,
                'date' => null,
            ],
            'works' => [],
            'areas' => [],
            'floors' => [],
            'warnings' => [],
            'uncertain' => [],
            'duplicates' => [],
            'needs_ocr' => false,
            'debug_rooms' => [],
        ];
    }

    public function normalizeNumber(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(',', '.', $value);

        return preg_replace('/\s+/', '', $value) ?? $value;
    }

    private function cleanLine(string $line): string
    {
        $line = preg_replace('/[\x{E000}-\x{F8FF}]/u', '', $line) ?? $line;
        $line = str_replace("\xC2\xA0", ' ', $line);

        return trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);
    }

    /**
     * @return array{room_number: string, room_name: string, square_meters: ?float}|null
     */
    private function parseRoomLine(string $line): ?array
    {
        if (! preg_match('/(?<![0-9])([0-3][.\-]\d{1,3}[a-zA-Z]?)(?![0-9.\-])/u', $line, $match)) {
            return null;
        }

        $number = $match[1];
        $squareMeters = $this->extractSquareMeters($line);
        if ($squareMeters !== null && abs($squareMeters - (float) str_replace(',', '.', $number)) < 0.001) {
            return null;
        }
        $rest = trim(str_replace($number, ' ', $line));
        if ($squareMeters === null) {
            $squareMeters = $this->extractSquareMeters($rest);
        }
        $name = $this->cleanName($rest);

        return [
            'room_number' => $number,
            'room_name' => $name,
            'square_meters' => $squareMeters,
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return array{room_name: string, square_meters: ?float}
     */
    private function lookAhead(array $lines, int $index): array
    {
        $name = '';
        $squareMeters = null;
        $limit = min($index + 2, count($lines) - 1);

        for ($next = $index + 1; $next <= $limit; $next++) {
            $line = $this->cleanLine($lines[$next]);
            if ($line === '' || $this->floorFromHeader($line) !== null) {
                break;
            }
            if ($this->parseRoomLine($line) !== null) {
                break;
            }

            if ($squareMeters === null) {
                $squareMeters = $this->extractSquareMeters($line);
            }
            $candidate = $this->cleanName($line);
            if ($name === '' && $candidate !== '') {
                $name = $candidate;
            }
            if ($name !== '' && $squareMeters !== null) {
                break;
            }
        }

        return ['room_name' => $name, 'square_meters' => $squareMeters];
    }

    private function extractSquareMeters(string $line): ?float
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/u', $line, $match)) {
            return null;
        }

        return DutchNumber::parse($match[1]);
    }

    private function cleanName(string $value): string
    {
        $value = preg_replace('/(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/u', '', $value) ?? $value;
        $value = preg_replace('/(\d+(?:[.,]\d+)?)\s*m(?:¹|1)\b/u', '', $value) ?? $value;
        $value = preg_replace('/\bschaal\b.*$/iu', '', $value) ?? $value;
        $value = trim($value, " \t-–—:|");
        if ($value === '' || preg_match('/^[\d.,\s]+$/', $value)) {
            return '';
        }
        if (mb_strlen($value) > 80) {
            return '';
        }

        return $value;
    }

    private function floorFromHeader(string $line): ?string
    {
        $flat = mb_strtolower($line);
        if (preg_match('/bouwlaag\s*:\s*(.+)$/iu', $line, $match)) {
            return $this->floorLabel()->canonical(trim($match[1]));
        }

        return $this->floorLabel()->canonical($flat);
    }

    private function floorFromFilename(?string $name): ?string
    {
        return $this->floorLabel()->fromFilename($name);
    }

    private function floorLabel(): FloorLabel
    {
        return new FloorLabel;
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
        $currentName = mb_strtolower((string) $current['room_name']);
        $incomingName = mb_strtolower((string) $incoming['room_name']);
        $number = mb_strtolower((string) $current['room_number']);
        if (($currentName === '' || $currentName === $number) && $incomingName !== '' && $incomingName !== $number) {
            $current['room_name'] = $incoming['room_name'];
        }
        $current['needs_review'] = ($current['room_name'] === '' || $current['room_name'] === $current['room_number'] || $current['square_meters'] === null);

        return $current;
    }

    private function colors(): DrawingColorMatcher
    {
        return $this->colors ??= new DrawingColorMatcher(new PdfPageGeometry);
    }
}
