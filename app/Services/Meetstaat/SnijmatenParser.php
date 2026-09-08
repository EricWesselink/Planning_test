<?php

namespace App\Services\Meetstaat;

use App\Enums\WorkUnit;
use App\Support\DutchNumber;

class SnijmatenParser
{
    public function __construct(private PdfTextExtractor $extractor) {}

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path, ?string $originalName = null): array
    {
        $extracted = $this->extractor->extractDrawing($path);
        $parsed = $this->parseText($extracted['text'], $originalName);
        $parsed['engine'] = $extracted['engine'];
        $parsed['needs_ocr'] = $extracted['needs_ocr'] && $parsed['areas'] === [];
        if ($parsed['needs_ocr']) {
            $parsed['warnings'][] = 'Deze snijmaten-PDF heeft geen bruikbare tekstlaag. Ruimtes kun je handmatig aanvullen.';
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseText(string $text, ?string $originalName = null): array
    {
        $lines = preg_split("/\n/", $text) ?: [];
        $currentFloor = $this->floorFromFilename($originalName) ?? 'Onbekend';
        $currentProduct = null;
        $areas = [];
        $uncertain = [];
        $warnings = [];

        foreach ($lines as $rawLine) {
            $line = $this->cleanLine($rawLine);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^totaal\b/iu', $line) || preg_match('/^pagina\b/iu', $line)) {
                continue;
            }

            if ($this->isProductContinuation($line) && is_string($currentProduct) && $currentProduct !== '') {
                $currentProduct = $this->cleanProductName($currentProduct.' '.$line);

                continue;
            }

            $headerFloor = $this->floorFromHeader($line);
            if ($headerFloor !== null && ! $this->isNamedFloorCutLine($line)) {
                $currentFloor = $headerFloor;

                continue;
            }

            $namedCut = $this->parseNamedFloorCutLine($line);
            if ($namedCut !== null) {
                $product = $currentProduct;
                if ($product === null || $product === '') {
                    $uncertain[] = ['line' => $line, 'reason' => 'Snijmaatregel zonder productkop.'];

                    continue;
                }
                $floor = $namedCut['floor'];
                $name = $namedCut['room_name'];
                $key = mb_strtolower($floor).'|'.mb_strtolower($name);
                if (! isset($areas[$key])) {
                    $areas[$key] = [
                        'key' => $key,
                        'floor' => $floor,
                        'room_number' => null,
                        'room_name' => $name,
                        'square_meters' => null,
                        'tasks' => [[
                            'work_name' => $product,
                            'unit' => $this->unitFromProductName($product),
                            'quantity' => 0.0,
                            'perimeter' => 0.0,
                            'seams' => 0.0,
                            'parts' => 1,
                        ]],
                        'source' => 'snijmaten',
                        'needs_review' => false,
                        'keep_separate' => false,
                    ];
                } else {
                    $areas[$key]['tasks'] = $this->appendTasks($areas[$key]['tasks'], [[
                        'work_name' => $product,
                        'unit' => $this->unitFromProductName($product),
                        'quantity' => 0.0,
                        'perimeter' => 0.0,
                        'seams' => 0.0,
                        'parts' => 1,
                    ]]);
                }

                continue;
            }

            if ($this->looksLikeProductName($line) && $this->parseLegacyRoomLine($line) === null) {
                $currentProduct = $this->cleanProductName($line);

                continue;
            }

            $parsed = $this->parseLegacyRoomLine($line);
            if ($parsed === null) {
                continue;
            }

            $product = $parsed['product'] ?? $currentProduct;
            if ($parsed['room_name'] === '' && $parsed['square_meters'] === null && $product === null) {
                $uncertain[] = ['line' => $line, 'reason' => 'Ruimte zonder naam, materiaal of m².'];

                continue;
            }

            $number = $this->normalizeNumber((string) $parsed['room_number']);
            $key = mb_strtolower($currentFloor).'|'.($number !== '' ? $number : mb_strtolower($parsed['room_name']));
            $tasks = [];
            if (is_string($product) && $product !== '') {
                $unit = $this->unitFromProductName($product);
                $tasks[] = [
                    'work_name' => $product,
                    'unit' => $unit,
                    'quantity' => $parsed['square_meters'] ?? 0.0,
                    'perimeter' => 0.0,
                    'seams' => 0.0,
                    'parts' => 1,
                ];
            }

            $incoming = [
                'key' => $key,
                'floor' => $currentFloor,
                'room_number' => $parsed['room_number'],
                'room_name' => $parsed['room_name'] !== '' ? $parsed['room_name'] : $parsed['room_number'],
                'square_meters' => $parsed['square_meters'],
                'tasks' => $tasks,
                'source' => 'snijmaten',
                'needs_review' => $parsed['square_meters'] === null || $parsed['room_name'] === '' || $product === null,
            ];

            if (! isset($areas[$key])) {
                $areas[$key] = $incoming;

                continue;
            }

            $areas[$key]['tasks'] = $this->appendTasks($areas[$key]['tasks'], $tasks);
            if ($areas[$key]['square_meters'] === null && $incoming['square_meters'] !== null) {
                $areas[$key]['square_meters'] = $incoming['square_meters'];
            }
            if ($areas[$key]['room_name'] === $areas[$key]['room_number'] && $incoming['room_name'] !== $incoming['room_number']) {
                $areas[$key]['room_name'] = $incoming['room_name'];
            }
        }

        $floors = [];
        foreach ($areas as $area) {
            $floors[$area['floor']] = true;
        }

        if ($areas === [] && trim($text) !== '') {
            $warnings[] = 'Geen ruimtes herkend in de snijmaten. Controleer het bestandstype of vul handmatig aan.';
        }

        return [
            'format' => 'snijmaten',
            'header' => [
                'customer_name' => null,
                'reference' => null,
                'project_name' => null,
                'project_number' => null,
                'date' => null,
            ],
            'works' => [],
            'areas' => array_values($areas),
            'floors' => array_keys($floors),
            'warnings' => $warnings,
            'uncertain' => $uncertain,
            'duplicates' => [],
            'needs_ocr' => false,
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
        $line = str_replace(["\xC2\xA0", "\t"], ' ', $line);

        return trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);
    }

    private function isNamedFloorCutLine(string $line): bool
    {
        return $this->parseNamedFloorCutLine($line) !== null;
    }

    /**
     * Snijmaatregels met bouwlaag + ruimtenaam + snijlengte (geen fysieke ruimte-m²).
     * Voorbeeld: "12 begane grond tekenlokaal 8.15" of "-99 verdieping 1 instructie 6.55"
     *
     * @return array{floor: string, room_name: string}|null
     */
    private function parseNamedFloorCutLine(string $line): ?array
    {
        if (! preg_match(
            '/^(?:\d+|-99)\s+(begane\s+grond|verdieping\s+\d+|kelder|souterrain|installatie(?:\s+ruimten?)?)\s+(.+?)\s+(\d+(?:[.,]\d+)?)\s*$/iu',
            $line,
            $match
        )) {
            return null;
        }

        $floor = $this->floorLabel()->canonical($match[1]) ?? mb_strtolower(trim($match[1]));
        $name = $this->cleanName($match[2]);
        if ($name === '' || preg_match('/^(totaal|groep|ruimte|tekening|baan)$/iu', $name)) {
            return null;
        }

        return [
            'floor' => $floor,
            'room_name' => $name,
        ];
    }

    /**
     * @return array{room_number: string, room_name: string, square_meters: ?float, product: ?string}|null
     */
    private function parseLegacyRoomLine(string $line): ?array
    {
        if (! preg_match('/(?<![0-9])(\d{1,2}[.\-]\d{1,3}[a-zA-Z]?)(?![0-9.\-])/u', $line, $match)) {
            return null;
        }

        $number = $match[1];
        $squareMeters = $this->extractSquareMeters($line);
        if ($squareMeters !== null && $this->normalizeNumber((string) $squareMeters) === $this->normalizeNumber($number)) {
            return null;
        }

        $rest = trim(str_replace($number, ' ', $line));
        $product = $this->extractProduct($rest);
        if ($product !== null) {
            $rest = trim(str_ireplace($product, ' ', $rest));
        }
        $name = $this->cleanName($rest);

        return [
            'room_number' => $number,
            'room_name' => $name,
            'square_meters' => $squareMeters,
            'product' => $product,
        ];
    }

    private function extractProduct(string $value): ?string
    {
        if (! preg_match('/(marmoleum[^,]*|plinten?[^,]*|pvc[^,]*|gietvloer[^,]*|tapijt[^,]*|vinyl[^,]*|linoleum[^,]*|coral[^,]*|coating[^,]*)/iu', $value, $match)) {
            return null;
        }

        $product = $this->cleanProductName($match[1]);

        return $product !== '' ? $product : null;
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
        $value = preg_replace('/\d+\s*[x×]\s*\d+/iu', '', $value) ?? $value;
        $value = trim($value, " \t-–—:|");
        if ($value === '' || preg_match('/^[\d.,\s]+$/', $value)) {
            return '';
        }
        if (mb_strlen($value) > 80) {
            return '';
        }

        return $value;
    }

    /**
     * Productregels die op de vorige productkop horen (PDF-regelbreuken).
     */
    private function isProductContinuation(string $line): bool
    {
        return (bool) preg_match(
            '/^(?:\/\s*)?(?:banen\s*\/\s*)?vinyl\b|^(?:banen\s*\/\s*vinyl\b)|^\d{4,}\s+white\s+moss\b/iu',
            $line
        );
    }

    private function looksLikeProductName(string $line): bool
    {
        if (preg_match('/^(snijmaten|pagina|totaal|bouwlaag|baan|groep|ruimte|tekening)\b/iu', $line)) {
            return false;
        }
        if (preg_match('/\d+\s*m(?:²|2|¹|1)?\b/u', $line)) {
            return false;
        }
        if ($this->isNamedFloorCutLine($line)) {
            return false;
        }
        // Alleen een vervolgregel is geen nieuwe productkop.
        if ($this->isProductContinuation($line) && ! preg_match('/tarkett|desso|marmoleum|entreemat|iq\s+natural|safe\.?\s*t|granit|classics/i', $line)) {
            return false;
        }

        return (bool) preg_match('/tarkett|desso|marmoleum|plint|gietvloer|entreemat|pvc|tapijt|linoleum|coral|coating|vinyl|granit|desert|safe\.?\s*t|directie|oak|classics/i', $line)
            || (bool) preg_match('/^[^,]{2,},\s*[^,]{1,},\s*[^,]{2,}$/', $line);
    }

    private function cleanProductName(string $name): string
    {
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = preg_replace('/\s*\/\s*/', ' / ', $name) ?? $name;

        return trim($name, ' ,/');
    }

    private function unitFromProductName(string $name): string
    {
        return str_contains(mb_strtolower($name), 'plint')
            ? WorkUnit::LinearMeter->value
            : WorkUnit::SquareMeter->value;
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function appendTasks(array $existing, array $incoming): array
    {
        $seen = [];
        foreach ($existing as $task) {
            $seen[mb_strtolower((string) ($task['work_name'] ?? ''))] = true;
        }
        foreach ($incoming as $task) {
            $name = mb_strtolower((string) ($task['work_name'] ?? ''));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $existing[] = $task;
            $seen[$name] = true;
        }

        return $existing;
    }

    private function floorFromHeader(string $line): ?string
    {
        if (preg_match('/bouwlaag\s*:\s*(.+)$/iu', $line, $match)) {
            return $this->floorLabel()->canonical(trim($match[1]));
        }

        return $this->floorLabel()->canonical($line);
    }

    private function floorFromFilename(?string $name): ?string
    {
        return $this->floorLabel()->fromFilename($name);
    }

    private function floorLabel(): FloorLabel
    {
        return new FloorLabel;
    }
}
