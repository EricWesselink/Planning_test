<?php

namespace App\Services\AreaWithoutM2Trial;

use App\Services\Meetstaat\PdfPageGeometry;
use App\Support\DutchNumber;
use App\Support\Format;
use App\Support\MaterialColor;

/**
 * Experimental floor-area probe. Never writes to quote calculations or reuse their parser.
 */
class AreaWithoutM2Analyzer
{
    public const STATUS_RELIABLE = 'reliable';

    public const STATUS_REVIEW = 'review';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const SOURCE_TEXT = 'text';

    public const SOURCE_IMAGE = 'image';

    private const ROOM_SIDE_MIN_MM = 1500;

    private const ROOM_SIDE_MAX_MM = 20000;

    private const DIMENSION_MIN_MM = 400;

    private const DIMENSION_MAX_MM = 30000;

    private const GREEN_ABS_M2 = 0.15;

    private const GREEN_PCT = 3.0;

    private const ANALYSIS_BUDGET_SECONDS = 28.0;

    private const MATCH_SECONDS_LEFT = 1.5;

    private const ROOM_NAME_PATTERN = '/^(woonkamer|woonkeuken|keuken|bijkeuken|hal|gang|entree|toilet|wc|badkamer|ensuite|slaapkamer(?:\s*\d+)?|overloop|serre|kantoor|opslag|berging|garage|techniek(?:ruimte)?|trappenhuis|wasruimte|zolder|kelder|werkkamer|speelkamer|eetkamer|meterkast|cv[\s\-]?ruimte|scullery|living|kitchen|bedroom|bathroom|storage)$/iu';

    /**
     * @var array{render: float, ocr: float, walls: float, rooms: float, matching: float, contours: float, extract: float, total: float}
     */
    private array $timings = [
        'render' => 0.0,
        'ocr' => 0.0,
        'walls' => 0.0,
        'rooms' => 0.0,
        'matching' => 0.0,
        'contours' => 0.0,
        'extract' => 0.0,
        'total' => 0.0,
    ];

    private float $deadline = 0.0;

    public function __construct(
        private PdfPageGeometry $geometry = new PdfPageGeometry,
        private RasterPageReader $raster = new RasterPageReader,
        private WallBoundDimensionMatcher $matcher = new WallBoundDimensionMatcher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyzeFile(string $path, string $filename = ''): array
    {
        $this->resetTimings();
        $this->deadline = microtime(true) + self::ANALYSIS_BUDGET_SECONDS;
        $started = hrtime(true);
        $source = self::SOURCE_TEXT;
        $engine = null;
        $warnings = [];
        $ocrMean = null;
        $pages = [];
        $previewPath = null;

        if ($this->pdfHasFontResource($path)) {
            $extractStarted = hrtime(true);
            $extracted = $this->geometry->extract($path);
            $this->timings['extract'] = $this->secondsSince($extractStarted);
            $pages = $extracted['pages'] ?? [];
        }

        if ($pages === [] || ! $this->hasUsableTextLayer($pages)) {
            $source = self::SOURCE_IMAGE;
            $raster = $this->raster->read($path);
            $engine = $raster['engine'];
            $this->timings['render'] = (float) ($raster['timings']['render'] ?? 0);
            $this->timings['ocr'] = (float) ($raster['timings']['ocr'] ?? 0);
            $this->timings['walls'] = (float) ($raster['timings']['walls'] ?? 0);
            $ocrMean = $raster['ocr_mean_confidence'] ?? null;
            if (($raster['pages'] ?? []) !== []) {
                $pages = $raster['pages'];
            }
            if (is_string($raster['error'] ?? null) && $raster['error'] !== '') {
                $warnings[] = $raster['error'];
            }
            $previewPath = is_string($raster['preview_path'] ?? null) ? $raster['preview_path'] : null;
        }

        $result = $this->analyzePages($pages, $filename, $source, $engine, $ocrMean);
        $this->timings['total'] = $this->secondsSince($started);
        $result['warnings'] = array_values(array_unique(array_merge($warnings, $result['warnings'])));
        $result['timings'] = $this->timings;
        $result['timing_labels'] = $this->timingLabels();
        $result['ocr_mean_confidence'] = $ocrMean;
        $result['preview_path'] = $previewPath;

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    public function analyzePages(array $pages, string $filename = '', string $sourceType = self::SOURCE_TEXT, ?string $ocrEngine = null, ?float $ocrMeanConfidence = null): array
    {
        if ($this->deadline <= 0) {
            $this->deadline = microtime(true) + self::ANALYSIS_BUDGET_SECONDS;
        }

        $roomsStarted = hrtime(true);
        $rooms = [];
        $pageDimensions = [];
        $pageNames = [];
        foreach ($pages as $page) {
            $classified = $this->classify($page['texts'] ?? [], (int) ($page['page'] ?? 1));
            foreach ($classified['dimensions'] as $dimension) {
                $pageDimensions[] = (int) $dimension['mm'];
            }
            foreach ($classified['names'] as $name) {
                if ($this->isRoomName((string) $name['text'])) {
                    $pageNames[] = (string) $name['text'];
                }
            }
            $this->timings['rooms'] += $this->secondsSince($roomsStarted);
            $roomsStarted = hrtime(true);
            foreach ($this->roomsFromClassification($page, $classified) as $room) {
                $rooms[] = $room;
            }
        }

        $warnings = [];
        if ($rooms === []) {
            $warnings[] = $sourceType === self::SOURCE_IMAGE
                ? 'Geen ruimtes herkend op de afbeelding. Controleer of namen en maatvoering leesbaar zijn.'
                : 'Geen ruimtes herkend. Controleer of de tekening ruimtenamen of ruimtenummers bevat.';
        }

        $recognizedNames = $this->uniqueSortedStrings($pageNames !== [] ? $pageNames : array_values(array_filter(array_map(
            fn (array $room): string => (string) ($room['room_name'] ?? ''),
            $rooms,
        ))));
        $calculated = [];
        $unavailable = [];
        foreach ($rooms as $room) {
            $label = trim((string) ($room['room_name'] ?? '').' '.(string) ($room['room_number'] ?? ''));
            if (is_numeric($room['calculated_m2'] ?? null)) {
                $calculated[] = $label;
            } else {
                $unavailable[] = $label;
            }
        }

        return [
            'filename' => $filename,
            'rooms' => $rooms,
            'warnings' => $warnings,
            'source_type' => $sourceType,
            'source_label' => $sourceType === self::SOURCE_IMAGE
                ? 'Brontype: afbeelding/scanned PDF'
                : 'Brontype: PDF met tekstlaag',
            'ocr_engine' => $ocrEngine,
            'ocr_mean_confidence' => $ocrMeanConfidence,
            'recognized_room_names' => $recognizedNames,
            'recognized_dimension_values' => $this->uniqueSortedInts($pageDimensions),
            'calculated_room_labels' => $calculated,
            'unavailable_room_labels' => $unavailable,
            'timings' => $this->timings,
            'timing_labels' => $this->timingLabels(),
            'geometry' => $this->pageGeometryOverlay($pages[0] ?? []),
            'geometry_debug' => $this->geometryDiagnosis($pages[0] ?? [], $rooms),
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $classified
     * @return list<array<string, mixed>>
     */
    private function roomsFromClassification(array $page, array $classified): array
    {
        $threshold = max(90.0, hypot((float) ($page['width'] ?? 1), (float) ($page['height'] ?? 1)) * 0.12);
        $page['drawing_scale'] = $this->drawingScale($page['texts'] ?? []);
        $numbered = $classified['anchors'];
        $nameAnchors = [];
        foreach ($classified['names'] as $nameItem) {
            $name = (string) $nameItem['text'];
            if (! $this->isRoomName($name)) {
                continue;
            }
            $nameAnchors[] = $nameItem + [
                'room_number' => '',
                'room_key' => $this->roomKey($nameItem + ['room_number' => '']),
            ];
        }

        $usedNameKeys = [];
        $numberedRooms = [];
        foreach ($numbered as $anchor) {
            $nameItem = $this->nearestNameItem($anchor, $classified['names'], $numbered, $threshold);
            $name = $nameItem !== null ? (string) $nameItem['text'] : null;
            if ($nameItem !== null) {
                $usedNameKeys[$this->itemKey($nameItem)] = true;
            }
            $numberedRooms[] = ['anchor' => $anchor, 'name' => $name];
        }

        $roomAnchors = $numbered;
        foreach ($nameAnchors as $nameAnchor) {
            if (isset($usedNameKeys[$this->itemKey($nameAnchor)])) {
                continue;
            }
            $roomAnchors[] = $nameAnchor;
        }

        $rooms = [];
        foreach ($numberedRooms as $numberedRoom) {
            $rooms[] = $this->roomFromAnchor(
                $numberedRoom['anchor'],
                $numberedRoom['name'],
                $classified,
                $page,
                $roomAnchors,
                $threshold,
            );
        }

        foreach ($nameAnchors as $anchor) {
            if (isset($usedNameKeys[$this->itemKey($anchor)])) {
                continue;
            }
            $rooms[] = $this->roomFromAnchor(
                $anchor,
                (string) $anchor['text'],
                $classified,
                $page,
                $roomAnchors,
                $threshold,
            );
        }

        return $rooms;
    }

    /**
     * @param  array{x: float, y: float, page: int, room_number?: string, room_key?: string}  $anchor
     * @param  array<string, mixed>  $classified
     * @param  array<string, mixed>  $page
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     */
    private function roomFromAnchor(array $anchor, ?string $name, array $classified, array $page, array $anchors, float $threshold): array
    {
        $printed = $this->nearestPrinted($anchor, $classified['printed'], $anchors, $threshold);
        $bound = [
            'box' => null,
            'horizontal' => null,
            'vertical' => null,
            'rejected' => [],
            'dimension_debug' => [],
            'confidence' => 0.0,
            'boundary' => [
                'left' => null,
                'right' => null,
                'top' => null,
                'bottom' => null,
                'closed_gaps' => [],
            ],
            'scale_mm_per_px' => null,
        ];
        $chosen = null;
        if ($this->secondsLeft() >= self::MATCH_SECONDS_LEFT) {
            $matchStarted = hrtime(true);
            $bound = $this->matcher->match($anchor, $classified['dimensions'], $page, $anchors);
            $this->timings['matching'] += $this->secondsSince($matchStarted);
            $contourStarted = hrtime(true);
            $chosen = $this->fromBoundMatch($bound);
            $this->timings['contours'] += $this->secondsSince($contourStarted);
        } else {
            $bound['rejected'][] = [
                'mm' => 0,
                'reason' => 'gestopt: tijdlimiet, geen extra zoekactie',
            ];
        }

        return $this->present(
            (string) ($anchor['room_number'] ?? ''),
            $name,
            $bound,
            $printed,
            $chosen,
            $page,
            $anchor,
        );
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @return array{
     *     anchors: list<array{text: string, x: float, y: float, page: int, room_number: string}>,
     *     names: list<array{text: string, x: float, y: float, page: int}>,
     *     dimensions: list<array{text: string, x: float, y: float, page: int, mm: int}>,
     *     pairs: list<array{text: string, x: float, y: float, page: int, a: int, b: int}>,
     *     printed: list<array{text: string, x: float, y: float, page: int, square_meters: float}>
     * }
     */
    private function classify(array $items, int $page): array
    {
        $printed = $this->printedAreas($items);
        $printedKeys = [];
        foreach ($printed as $item) {
            $printedKeys[$this->itemKey($item)] = true;
        }

        $anchors = [];
        $names = [];
        $dimensions = [];
        $pairs = [];
        $preferBuildingCodes = false;
        foreach ($items as $item) {
            if (preg_match('/\b[A-Z]-\d{2}-\d{2}\b/u', $item['text'])) {
                $preferBuildingCodes = true;
                break;
            }
        }

        foreach ($items as $item) {
            $text = trim((string) $item['text']);
            if ($text === '') {
                continue;
            }

            if (preg_match_all('/\b([A-Z]-\d{2}-\d{2})\b/u', $text, $matches)) {
                foreach ($matches[1] as $number) {
                    $anchors[] = $item + [
                        'room_number' => $number,
                        'room_key' => 'nr:'.$item['page'].':'.$number,
                    ];
                }
            } elseif (! $preferBuildingCodes && preg_match_all('/\b(\d{1,2}[.\-]\d{2}[a-zA-Z]?)\b/u', $text, $matches)) {
                foreach ($matches[1] as $number) {
                    if (! $this->isPrintedAreaToken($text)) {
                        $anchors[] = $item + [
                            'room_number' => $number,
                            'room_key' => 'nr:'.$item['page'].':'.$number,
                        ];
                    }
                }
            }

            if (preg_match_all('/(\d{3,5})\s*[x×]\s*(\d{3,5})/u', $text, $pairMatches, PREG_SET_ORDER)) {
                foreach ($pairMatches as $pair) {
                    $a = (int) $pair[1];
                    $b = (int) $pair[2];
                    if ($this->isRoomSide($a) && $this->isRoomSide($b)) {
                        $pairs[] = $item + ['a' => $a, 'b' => $b];
                        $dimensions[] = $item + ['mm' => $a];
                        $dimensions[] = $item + ['mm' => $b];
                    }
                }
            }

            if (! isset($printedKeys[$this->itemKey($item)]) && ! $this->isPrintedAreaToken($text)) {
                if (preg_match('/^(\d{3,5})(?:\s*mm)?$/iu', $text, $match)) {
                    $mm = (int) $match[1];
                    if ($mm >= self::DIMENSION_MIN_MM && $mm <= self::DIMENSION_MAX_MM) {
                        $dimensions[] = $item + ['mm' => $mm];
                    }
                } elseif (preg_match_all('/(?<![0-9.,])(\d{3,5})(?!\s*m(?:²|2)\b)(?:\s*mm)?(?![0-9])/u', $text, $mmMatches)) {
                    foreach ($mmMatches[1] as $raw) {
                        $mm = (int) $raw;
                        if ($mm >= self::DIMENSION_MIN_MM && $mm <= self::DIMENSION_MAX_MM && ! $this->looksLikeRoomNumber($raw)) {
                            $dimensions[] = $item + ['mm' => $mm];
                        }
                    }
                }
            }

            $name = $this->nameFrom($text);
            if ($name !== null) {
                $names[] = ['text' => $name, 'x' => $item['x'], 'y' => $item['y'], 'page' => $item['page']];
            }
        }

        unset($page);

        return [
            'anchors' => $anchors,
            'names' => $names,
            'dimensions' => $this->uniqueDimensions($dimensions),
            'pairs' => $pairs,
            'printed' => $printed,
        ];
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @return list<array{text: string, x: float, y: float, page: int, square_meters: float}>
     */
    private function printedAreas(array $items): array
    {
        $units = [];
        foreach ($items as $item) {
            if (preg_match('/^m(?:²|2)$/iu', trim((string) $item['text']))) {
                $units[] = $item;
            }
        }

        $printed = [];
        foreach ($items as $item) {
            $text = trim((string) $item['text']);
            if (preg_match_all('/(\d{1,4}(?:[.,]\d+)?)\s*m(?:²|2)\b/u', $text, $matches)) {
                foreach ($matches[1] as $raw) {
                    $value = DutchNumber::parse($raw);
                    if ($value !== null && $value >= 0.4 && $value <= 5000) {
                        $printed[] = $item + ['square_meters' => $value];
                    }
                }

                continue;
            }

            if (! preg_match('/^\d{1,4}(?:[.,]\d+)?$/u', $text)) {
                continue;
            }
            $value = DutchNumber::parse($text);
            if ($value === null || $value < 0.4 || $value > 5000) {
                continue;
            }
            foreach ($units as $unit) {
                if ((int) $unit['page'] !== (int) $item['page']) {
                    continue;
                }
                if (hypot((float) $unit['x'] - (float) $item['x'], (float) $unit['y'] - (float) $item['y']) > 36) {
                    continue;
                }
                $printed[] = $item + ['square_meters' => $value];
                break;
            }
        }

        return $printed;
    }

    /**
     * @param  array{
     *     box: ?array{left: float, right: float, bottom: float, top: float, width: float, height: float},
     *     horizontal: ?array<string, mixed>,
     *     vertical: ?array<string, mixed>,
     *     rejected: list<array{mm: int, reason: string}>,
     *     confidence: float
     * }  $bound
     * @return array{area: float, trace: string, method: string, reliable: bool, confidence: float}|null
     */
    private function fromBoundMatch(array $bound): ?array
    {
        $horizontal = $bound['horizontal'];
        $vertical = $bound['vertical'];
        $box = $bound['box'];
        if (is_array($horizontal) && is_array($vertical) && ($bound['confidence'] ?? 0) >= 0.85) {
            $a = (int) $horizontal['mm'];
            $b = (int) $vertical['mm'];
            $area = $this->mmPairArea($a, $b);
            if ($area === null) {
                return null;
            }
            $fromChain = ($horizontal['source'] ?? '') === 'chain' && ($vertical['source'] ?? '') === 'chain';

            return [
                'area' => $area,
                'trace' => $this->pairTrace($a, $b, $area),
                'method' => $fromChain ? 'maatketting' : 'lengte × breedte',
                'reliable' => true,
                'confidence' => (float) $bound['confidence'],
            ];
        }

        $local = is_array($horizontal) ? $horizontal : $vertical;
        if (! is_array($local) || ! is_array($box) || ($local['source'] ?? '') !== 'chain') {
            return null;
        }

        $mm = (int) $local['mm'];
        $edge = is_array($horizontal) ? (float) $box['width'] : (float) $box['height'];
        if ($edge < 8 || $mm < 400) {
            return null;
        }
        $scale = $mm / $edge;
        $area = ((float) $box['width'] * (float) $box['height'] * ($scale ** 2)) / 1_000_000;
        if ($area < 0.4 || $area > 5000) {
            return null;
        }

        return [
            'area' => round($area, 2),
            'trace' => 'contour + schaal uit lokale maatlijn '.$mm,
            'method' => 'contour + schaal',
            'reliable' => false,
            'confidence' => (float) $bound['confidence'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     */
    private function hasUsableTextLayer(array $pages): bool
    {
        $words = 0;
        foreach ($pages as $page) {
            foreach ($page['texts'] ?? [] as $item) {
                $text = trim((string) ($item['text'] ?? ''));
                if (preg_match('/\b[A-Z]-\d{2}-\d{2}\b/u', $text) || preg_match('/\b\d{1,2}[.\-]\d{2}[a-zA-Z]?\b/u', $text)) {
                    return true;
                }
                if (preg_match('/[A-Za-zÀ-ÿ]{3,}/u', $text)) {
                    $words++;
                }
                if ($this->isRoomName($text) || $this->isRoomName((string) ($this->nameFrom($text) ?? ''))) {
                    return true;
                }
            }
        }

        return $words >= 8;
    }

    /**
     * @param  array<string, mixed>  $bound
     * @param  array{area: float, trace: string, method: string, reliable: bool, confidence?: float}|null  $chosen
     * @param  array<string, mixed>  $page
     * @param  array{x?: float, y?: float}  $anchor
     * @return array<string, mixed>
     */
    private function present(string $roomNumber, ?string $name, array $bound, ?float $printed, ?array $chosen, array $page = [], array $anchor = []): array
    {
        $horizontal = is_array($bound['horizontal'] ?? null) ? $bound['horizontal'] : null;
        $vertical = is_array($bound['vertical'] ?? null) ? $bound['vertical'] : null;
        $boundary = is_array($bound['boundary'] ?? null) ? $bound['boundary'] : [];
        $closedGaps = array_values(array_filter(
            is_array($boundary['closed_gaps'] ?? null) ? $boundary['closed_gaps'] : [],
            fn (mixed $gap): bool => is_string($gap) && $gap !== '',
        ));
        $mm = [];
        if ($horizontal !== null) {
            $mm[] = (int) $horizontal['mm'];
        }
        if ($vertical !== null) {
            $mm[] = (int) $vertical['mm'];
        }
        $mm = array_values(array_unique($mm));
        sort($mm, SORT_NUMERIC);
        $calculated = $chosen['area'] ?? null;
        $status = self::STATUS_UNAVAILABLE;
        if (is_numeric($calculated)) {
            $status = ($chosen['reliable'] ?? false) ? self::STATUS_RELIABLE : self::STATUS_REVIEW;
            if ($printed !== null) {
                $abs = abs((float) $calculated - $printed);
                $pct = $printed > 0 ? abs(100 * ((float) $calculated - $printed) / $printed) : 0.0;
                if ($abs > self::GREEN_ABS_M2 && $pct > self::GREEN_PCT) {
                    $status = self::STATUS_REVIEW;
                }
            }
        }

        return [
            'room_name' => $name,
            'room_number' => $roomNumber,
            'recognized_dimensions' => $mm,
            'dimensions_label' => $mm === [] ? '—' : implode(', ', array_map(strval(...), $mm)),
            'method' => $chosen['method'] ?? '—',
            'trace' => $chosen['trace'] ?? 'Geen betrouwbare lokale maatvoering gekoppeld aan de wanden van deze ruimte.',
            'calculated_m2' => is_numeric($calculated) ? round((float) $calculated, 2) : null,
            'printed_m2' => $printed,
            'deviation_m2' => is_numeric($calculated) && $printed !== null
                ? round((float) $calculated - $printed, 2)
                : null,
            'deviation_percent' => is_numeric($calculated) && $printed !== null && $printed > 0
                ? round(100 * ((float) $calculated - $printed) / $printed, 2)
                : null,
            'calculated_label' => is_numeric($calculated) ? Format::qty($calculated, 2).' m²' : '—',
            'printed_label' => $printed !== null ? Format::qty($printed, 2).' m²' : '—',
            'deviation_label' => $this->deviationLabel(
                is_numeric($calculated) ? (float) $calculated : null,
                $printed,
            ),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'confidence' => round((float) ($bound['confidence'] ?? 0), 2),
            'horizontal_mm' => $horizontal['mm'] ?? null,
            'horizontal_position' => $horizontal === null ? '—' : 'x='.round((float) $horizontal['x']).', y='.round((float) $horizontal['y']),
            'horizontal_walls' => $horizontal['wall_label'] ?? '—',
            'vertical_mm' => $vertical['mm'] ?? null,
            'vertical_position' => $vertical === null ? '—' : 'x='.round((float) $vertical['x']).', y='.round((float) $vertical['y']),
            'vertical_walls' => $vertical['wall_label'] ?? '—',
            'rejected' => $bound['rejected'] ?? [],
            'wall_left' => is_string($boundary['left'] ?? null) ? $boundary['left'] : '—',
            'wall_right' => is_string($boundary['right'] ?? null) ? $boundary['right'] : '—',
            'wall_top' => is_string($boundary['top'] ?? null) ? $boundary['top'] : '—',
            'wall_bottom' => is_string($boundary['bottom'] ?? null) ? $boundary['bottom'] : '—',
            'wall_debug' => is_array($boundary['wall_debug'] ?? null) ? $boundary['wall_debug'] : ['vertical' => [], 'horizontal' => [], 'tested_pair' => []],
            'closed_gaps' => $closedGaps,
            'closed_gaps_label' => $closedGaps === [] ? 'geen' : implode('; ', $closedGaps),
            'scale_mm_per_px' => is_numeric($bound['scale_mm_per_px'] ?? null)
                ? round((float) $bound['scale_mm_per_px'], 3)
                : null,
            'drawing_scale' => isset($page['drawing_scale']) ? (int) $page['drawing_scale'] : null,
            'ocr_x' => round((float) ($anchor['x'] ?? 0), 1),
            'ocr_y' => round((float) ($anchor['y'] ?? 0), 1),
            'horizontal_segment' => $horizontal['segment'] ?? '—',
            'horizontal_endpoints' => $horizontal['endpoints'] ?? '—',
            'horizontal_expected' => $horizontal['expected'] ?? '—',
            'horizontal_delta' => $horizontal['delta'] ?? '—',
            'vertical_segment' => $vertical['segment'] ?? '—',
            'vertical_endpoints' => $vertical['endpoints'] ?? '—',
            'vertical_expected' => $vertical['expected'] ?? '—',
            'vertical_delta' => $vertical['delta'] ?? '—',
            'dimension_debug' => is_array($bound['dimension_debug'] ?? null) ? $bound['dimension_debug'] : [],
            'overlay' => $this->overlayGeometry($bound, $page, $anchor, $name, $roomNumber),
        ];
    }

    /**
     * @param  array<string, mixed>  $bound
     * @param  array<string, mixed>  $page
     * @param  array{x?: float, y?: float}  $anchor
     * @return array<string, mixed>|null
     */
    private function overlayGeometry(array $bound, array $page, array $anchor, ?string $name = null, string $roomNumber = ''): ?array
    {
        $pageWidth = (float) ($page['width'] ?? 0);
        $pageHeight = (float) ($page['height'] ?? 0);
        if ($pageWidth < 1 || $pageHeight < 1) {
            return null;
        }
        $box = is_array($bound['box'] ?? null) ? $bound['box'] : null;
        $boundary = is_array($bound['boundary'] ?? null) ? $bound['boundary'] : [];
        $overlay = $this->overlayPercents($box, $pageWidth, $pageHeight) ?? [
            'left' => null,
            'top' => null,
            'width' => null,
            'height' => null,
        ];
        $overlay['anchor'] = [
            'x' => round(100 * ((float) ($anchor['x'] ?? 0)) / $pageWidth, 2),
            'y' => round(100 * ($pageHeight - (float) ($anchor['y'] ?? 0)) / $pageHeight, 2),
            'label' => is_string($name) && $name !== '' ? $name : 'Ruimte',
        ];
        $overlay['chip'] = $this->overlayChip($overlay, $roomNumber, $name, $boundary, $pageWidth, $pageHeight);
        $overlay['walls'] = $this->overlayWallLines($boundary, $pageWidth, $pageHeight, $overlay['anchor']['label']);
        $overlay['candidates'] = $this->overlayCandidateLines($boundary, $pageWidth, $pageHeight);
        $raw = $this->overlayNamedWalls($page['raw_walls'] ?? [], $pageWidth, $pageHeight, 'lijn');
        $overlay['raw'] = $raw;
        $overlay['raw_v'] = array_values(array_filter($raw, fn (array $line): bool => ($line['axis'] ?? '') === 'v'));
        $overlay['raw_h'] = array_values(array_filter($raw, fn (array $line): bool => ($line['axis'] ?? '') === 'h'));
        $overlay['bands'] = $this->overlayNamedWalls(array_merge(
            $page['wall_extract']['bands_h'] ?? [],
            $page['wall_extract']['bands_v'] ?? [],
        ), $pageWidth, $pageHeight, WallAxisAssembler::KIND_BAND);
        $overlay['axes'] = $this->overlayNamedWalls(
            $page['wall_extract']['axes'] ?? ($boundary['main_walls'] ?? []),
            $pageWidth,
            $pageHeight,
        );
        $overlay['horizontal'] = $this->overlayLine($bound['horizontal']['overlay'] ?? null, $pageWidth, $pageHeight);
        $overlay['vertical'] = $this->overlayLine($bound['vertical']['overlay'] ?? null, $pageWidth, $pageHeight);

        return $overlay;
    }

    /**
     * Page-level detection overlay so the preview is not limited to the first room's clustered walls.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function pageGeometryOverlay(array $page): array
    {
        $pageWidth = (float) ($page['width'] ?? 0);
        $pageHeight = (float) ($page['height'] ?? 0);
        $raw = $this->overlayNamedWalls($page['raw_walls'] ?? [], $pageWidth, $pageHeight, 'lijn');

        return [
            'raw_v' => array_values(array_filter($raw, fn (array $line): bool => ($line['axis'] ?? '') === 'v')),
            'raw_h' => array_values(array_filter($raw, fn (array $line): bool => ($line['axis'] ?? '') === 'h')),
            'bands' => $this->overlayNamedWalls(array_merge(
                $page['wall_extract']['bands_h'] ?? [],
                $page['wall_extract']['bands_v'] ?? [],
            ), $pageWidth, $pageHeight, WallAxisAssembler::KIND_BAND),
            'axes' => $this->overlayNamedWalls(
                $page['wall_extract']['axes'] ?? ($page['walls'] ?? []),
                $pageWidth,
                $pageHeight,
            ),
        ];
    }

    /**
     * Report whether existing detections sit to the right of / below the bedroom OCR points.
     *
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $rooms
     * @return array{right: string, right_detail: string, bottom: string, bottom_detail: string, counts: string, log: list<string>}
     */
    private function geometryDiagnosis(array $page, array $rooms): array
    {
        $items = $this->detectionItems($page);
        $verticals = array_values(array_filter($items, fn (array $item): bool => $item['axis'] === 'v'));
        $horizontals = array_values(array_filter($items, fn (array $item): bool => $item['axis'] === 'h'));
        $ocrX = $this->bedroomOcrExtreme($rooms, 'ocr_x', true);
        $ocrY = $this->bedroomOcrExtreme($rooms, 'ocr_y', false);

        $rightmost = $this->extremeDetection($verticals, 'pos', true);
        $lowest = $this->extremeDetection($horizontals, 'pos', false);

        $rightFound = $rightmost !== null && $ocrX !== null && $rightmost['pos'] > $ocrX + 8;
        $bottomFound = $lowest !== null && $ocrY !== null && $lowest['pos'] < $ocrY - 8;

        return [
            'right' => $rightFound ? 'rechter buitengevel: gevonden' : 'rechter buitengevel: niet gevonden',
            'right_detail' => $this->facadeDetail('vertical', $rightmost, $rightFound, $ocrX, 'OCR x'),
            'bottom' => $bottomFound ? 'onderste buitengevel: gevonden' : 'onderste buitengevel: niet gevonden',
            'bottom_detail' => $this->facadeDetail('horizontal', $lowest, $bottomFound, $ocrY, 'OCR y'),
            'counts' => 'ruwe V '.count(array_filter($items, fn (array $item): bool => $item['axis'] === 'v' && $item['layer'] === 'lijn'))
                .' · ruwe H '.count(array_filter($items, fn (array $item): bool => $item['axis'] === 'h' && $item['layer'] === 'lijn'))
                .' · banden '.count(array_filter($items, fn (array $item): bool => $item['layer'] === 'band'))
                .' · assen '.count(array_filter($items, fn (array $item): bool => $item['layer'] === 'as')),
            'log' => $this->rightSearchLog($page, $ocrX, $verticals),
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  list<array{axis: string, pos: float, span_lo: float, span_hi: float, bron: string, layer: string}>  $verticals
     * @return list<string>
     */
    private function rightSearchLog(array $page, ?float $ocrX, array $verticals): array
    {
        $pageWidth = (float) ($page['width'] ?? 0);
        $start = $ocrX ?? max(0.0, $pageWidth * 0.7);
        $end = $pageWidth > 1 ? $pageWidth : $start;
        $log = ['Rechter zoekgebied x='.round($start, 1).'..'.round($end)];
        $candidates = is_array($page['wall_extract']['vertical_candidates'] ?? null)
            ? $page['wall_extract']['vertical_candidates']
            : [];
        $inRegion = array_values(array_filter(
            $candidates,
            fn (array $row): bool => (float) ($row['x'] ?? 0) >= $start - 0.5,
        ));
        if ($inRegion === []) {
            $detected = array_values(array_filter($verticals, fn (array $item): bool => $item['pos'] >= $start - 0.5));
            $log[] = 'verticale donkere runs: '.count($detected);
            if ($detected === []) {
                $log[] = 'geen verticale kandidaat rechts van het zoekgebied';
            }
            foreach ($detected as $item) {
                $log[] = 'kandidaat x='.round($item['pos'], 1)
                    .' y='.round($item['span_lo']).'–'.round($item['span_hi'])
                    .' breedte=— → geaccepteerd ('.$item['bron'].')';
            }

            return $log;
        }
        $log[] = 'verticale donkere runs: '.count($inRegion);
        foreach ($this->compactRightCandidates($inRegion) as $line) {
            $log[] = $line;
        }

        return $log;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<string>
     */
    private function compactRightCandidates(array $candidates): array
    {
        $lines = [];
        $count = count($candidates);
        $i = 0;
        while ($i < $count) {
            $row = $candidates[$i];
            $y1 = min((float) ($row['y1'] ?? 0), (float) ($row['y2'] ?? 0));
            $y2 = max((float) ($row['y1'] ?? 0), (float) ($row['y2'] ?? 0));
            $reason = (string) ($row['reason'] ?? '');
            $decision = (string) ($row['decision'] ?? '');
            if ($decision === 'rejected' && $reason === 'te kort') {
                $j = $i;
                while ($j + 1 < $count) {
                    $next = $candidates[$j + 1];
                    if ((string) ($next['decision'] ?? '') !== 'rejected' || (string) ($next['reason'] ?? '') !== 'te kort') {
                        break;
                    }
                    $nextY1 = min((float) ($next['y1'] ?? 0), (float) ($next['y2'] ?? 0));
                    $nextY2 = max((float) ($next['y1'] ?? 0), (float) ($next['y2'] ?? 0));
                    if (abs($nextY1 - $y1) > 8 || abs($nextY2 - $y2) > 8) {
                        break;
                    }
                    $j++;
                }
                if ($j > $i) {
                    $lines[] = 'kandidaat x='.round((float) ($row['x'] ?? 0), 1).'–'.round((float) ($candidates[$j]['x'] ?? 0), 1)
                        .' y='.round($y1).'–'.round($y2)
                        .' breedte=— → afgewezen omdat te kort ('.($j - $i + 1).' kolommen)';
                    $i = $j + 1;

                    continue;
                }
            }
            $verdict = $decision === 'accepted'
                ? 'geaccepteerd ('.$reason.')'
                : 'afgewezen omdat '.$reason;
            $lines[] = 'kandidaat x='.round((float) ($row['x'] ?? 0), 1)
                .' y='.round($y1).'–'.round($y2)
                .' breedte='.round((float) ($row['width'] ?? 1), 1)
                .' → '.$verdict;
            $i++;
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return list<array{axis: string, pos: float, span_lo: float, span_hi: float, bron: string, layer: string}>
     */
    private function detectionItems(array $page): array
    {
        $items = [];
        foreach ($page['raw_walls'] ?? [] as $wall) {
            $item = $this->detectionItem($wall, 'lijn', 'lijn');
            if ($item !== null) {
                $items[] = $item;
            }
        }
        foreach (array_merge($page['wall_extract']['bands_h'] ?? [], $page['wall_extract']['bands_v'] ?? []) as $wall) {
            $item = $this->detectionItem($wall, 'band', 'band');
            if ($item !== null) {
                $items[] = $item;
            }
        }
        foreach ($page['wall_extract']['axes'] ?? [] as $wall) {
            $item = $this->detectionItem($wall, (string) ($wall['kind'] ?? 'lijn'), 'as');
            if ($item !== null) {
                $items[] = $item;
            }
        }
        if ($items !== []) {
            return $items;
        }
        foreach ($page['walls'] ?? [] as $wall) {
            $item = $this->detectionItem($wall, (string) ($wall['kind'] ?? 'lijn'), 'as');
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $wall
     * @return array{axis: string, pos: float, span_lo: float, span_hi: float, bron: string, layer: string}|null
     */
    private function detectionItem(array $wall, string $bron, string $layer): ?array
    {
        $x1 = (float) ($wall['x1'] ?? $wall['x'] ?? 0);
        $y1 = (float) ($wall['y1'] ?? $wall['y'] ?? 0);
        $x2 = (float) ($wall['x2'] ?? $wall['x'] ?? 0);
        $y2 = (float) ($wall['y2'] ?? $wall['y'] ?? 0);
        $axis = (string) ($wall['axis'] ?? '');
        if ($axis !== 'h' && $axis !== 'v') {
            $axis = abs($x2 - $x1) < abs($y2 - $y1) + 0.1 ? 'v' : 'h';
        }
        $kind = (string) ($wall['kind'] ?? $bron);
        if ($kind === WallAxisAssembler::KIND_PAIR) {
            $bron = 'paar';
        } elseif ($kind === WallAxisAssembler::KIND_CLUSTER) {
            $bron = 'cluster';
        } elseif ($kind === WallAxisAssembler::KIND_BAND) {
            $bron = 'band';
        } else {
            $bron = $bron === 'band' ? 'band' : 'lijn';
        }

        if ($axis === 'v') {
            return [
                'axis' => 'v',
                'pos' => ($x1 + $x2) / 2,
                'span_lo' => min($y1, $y2),
                'span_hi' => max($y1, $y2),
                'bron' => $bron,
                'layer' => $layer,
            ];
        }

        return [
            'axis' => 'h',
            'pos' => ($y1 + $y2) / 2,
            'span_lo' => min($x1, $x2),
            'span_hi' => max($x1, $x2),
            'bron' => $bron,
            'layer' => $layer,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     */
    private function bedroomOcrExtreme(array $rooms, string $key, bool $maximum): ?float
    {
        $values = [];
        foreach ($rooms as $room) {
            $name = strtoupper((string) ($room['room_name'] ?? ''));
            if (! str_contains($name, 'SLAAPKAMER')) {
                continue;
            }
            if (! is_numeric($room[$key] ?? null)) {
                continue;
            }
            $values[] = (float) $room[$key];
        }
        if ($values === []) {
            return null;
        }

        return $maximum ? max($values) : min($values);
    }

    /**
     * @param  list<array{pos: float, span_lo: float, span_hi: float, bron: string}>  $items
     * @return array{pos: float, span_lo: float, span_hi: float, bron: string}|null
     */
    private function extremeDetection(array $items, string $key, bool $maximum): ?array
    {
        $best = null;
        foreach ($items as $item) {
            if ($best === null) {
                $best = $item;

                continue;
            }
            if ($maximum && $item[$key] > $best[$key]) {
                $best = $item;
            }
            if (! $maximum && $item[$key] < $best[$key]) {
                $best = $item;
            }
        }

        return $best;
    }

    /**
     * @param  array{pos: float, span_lo: float, span_hi: float, bron: string}|null  $item
     */
    private function facadeDetail(string $orientation, ?array $item, bool $found, ?float $ocr, string $ocrLabel): string
    {
        if ($item === null) {
            return $orientation === 'vertical'
                ? 'geen verticale lijn, band of as gedetecteerd'
                : 'geen horizontale lijn, band of as gedetecteerd';
        }
        if ($orientation === 'vertical') {
            $coords = 'x = '.round($item['pos'], 1).', y-bereik = '.round($item['span_lo']).'–'.round($item['span_hi']).', bron = '.$item['bron'];
        } else {
            $coords = 'y = '.round($item['pos'], 1).', x-bereik = '.round($item['span_lo']).'–'.round($item['span_hi']).', bron = '.$item['bron'];
        }
        if ($found) {
            return $coords;
        }
        if ($ocr === null) {
            return $coords.' (geen slaapkamer-OCR om tegen af te zetten)';
        }

        return $coords.' (niet '.($orientation === 'vertical' ? 'rechts van' : 'onder').' '.$ocrLabel.'='.round($ocr, 1).')';
    }

    /**
     * @param  array<string, mixed>  $boundary
     * @return list<array<string, mixed>>
     */
    private function overlayWallLines(array $boundary, float $pageWidth, float $pageHeight, string $roomLabel): array
    {
        $left = $boundary['left_pos'] ?? null;
        $right = $boundary['right_pos'] ?? null;
        $top = $boundary['top_pos'] ?? null;
        $bottom = $boundary['bottom_pos'] ?? null;
        $y1 = is_numeric($bottom) ? (float) $bottom : 0.0;
        $y2 = is_numeric($top) ? (float) $top : $pageHeight;
        $x1 = is_numeric($left) ? (float) $left : 0.0;
        $x2 = is_numeric($right) ? (float) $right : $pageWidth;
        $lines = [];
        if (is_numeric($left)) {
            $lines[] = $this->overlayLine([
                'x1' => (float) $left, 'y1' => $y1, 'x2' => (float) $left, 'y2' => $y2,
                'side' => 'links', 'caption' => $roomLabel.' · links',
            ], $pageWidth, $pageHeight);
        }
        if (is_numeric($right)) {
            $lines[] = $this->overlayLine([
                'x1' => (float) $right, 'y1' => $y1, 'x2' => (float) $right, 'y2' => $y2,
                'side' => 'rechts', 'caption' => $roomLabel.' · rechts',
            ], $pageWidth, $pageHeight);
        }
        if (is_numeric($top)) {
            $lines[] = $this->overlayLine([
                'x1' => $x1, 'y1' => (float) $top, 'x2' => $x2, 'y2' => (float) $top,
                'side' => 'boven', 'caption' => $roomLabel.' · boven',
            ], $pageWidth, $pageHeight);
        }
        if (is_numeric($bottom)) {
            $lines[] = $this->overlayLine([
                'x1' => $x1, 'y1' => (float) $bottom, 'x2' => $x2, 'y2' => (float) $bottom,
                'side' => 'onder', 'caption' => $roomLabel.' · onder',
            ], $pageWidth, $pageHeight);
        }

        return array_values(array_filter($lines));
    }

    /**
     * @param  array<string, mixed>  $boundary
     * @return list<array{x1: float, y1: float, x2: float, y2: float}>
     */
    private function overlayCandidateLines(array $boundary, float $pageWidth, float $pageHeight): array
    {
        $lines = [];
        foreach ($boundary['main_walls'] ?? [] as $wall) {
            $line = $this->overlayLine([
                'x1' => (float) ($wall['x1'] ?? 0),
                'y1' => (float) ($wall['y1'] ?? 0),
                'x2' => (float) ($wall['x2'] ?? 0),
                'y2' => (float) ($wall['y2'] ?? 0),
            ], $pageWidth, $pageHeight);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     * @return list<array<string, mixed>>
     */
    private function overlayNamedWalls(array $walls, float $pageWidth, float $pageHeight, ?string $defaultKind = null): array
    {
        $lines = [];
        foreach ($walls as $wall) {
            $axis = (string) ($wall['axis'] ?? '');
            if ($axis !== 'h' && $axis !== 'v') {
                $x1 = (float) ($wall['x1'] ?? $wall['x'] ?? 0);
                $y1 = (float) ($wall['y1'] ?? $wall['y'] ?? 0);
                $x2 = (float) ($wall['x2'] ?? $wall['x'] ?? 0);
                $y2 = (float) ($wall['y2'] ?? $wall['y'] ?? 0);
                $axis = abs($x2 - $x1) < abs($y2 - $y1) + 0.1 ? 'v' : 'h';
            }
            $line = $this->overlayLine([
                'x1' => (float) ($wall['x1'] ?? $wall['x'] ?? 0),
                'y1' => (float) ($wall['y1'] ?? $wall['y'] ?? 0),
                'x2' => (float) ($wall['x2'] ?? $wall['x'] ?? 0),
                'y2' => (float) ($wall['y2'] ?? $wall['y'] ?? 0),
                'role' => $wall['role'] ?? null,
                'kind' => $wall['kind'] ?? $defaultKind,
                'axis' => $axis,
                'caption' => $this->wallCaption($wall, $defaultKind, $axis),
            ], $pageWidth, $pageHeight);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $wall
     */
    private function wallCaption(array $wall, ?string $defaultKind, string $axis): string
    {
        $role = is_string($wall['role'] ?? null) && $wall['role'] !== '' ? (string) $wall['role'] : null;
        $kind = is_string($wall['kind'] ?? null) && $wall['kind'] !== '' ? (string) $wall['kind'] : ($defaultKind ?? 'lijn');
        $parts = array_values(array_filter([$role, $kind]));
        if ($axis === 'v') {
            $parts[] = 'x='.round((float) ($wall['x1'] ?? $wall['x'] ?? 0));
        } else {
            $parts[] = 'y='.round((float) ($wall['y1'] ?? $wall['y'] ?? 0));
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array{x1?: float, y1?: float, x2?: float, y2?: float, role?: mixed, kind?: mixed, axis?: mixed, side?: mixed, caption?: mixed}|null  $line
     * @return array<string, mixed>|null
     */
    private function overlayLine(?array $line, float $pageWidth, float $pageHeight): ?array
    {
        if ($line === null || $pageWidth < 1 || $pageHeight < 1) {
            return null;
        }

        $out = [
            'x1' => round(100 * ((float) ($line['x1'] ?? 0)) / $pageWidth, 2),
            'y1' => round(100 * ($pageHeight - (float) ($line['y1'] ?? 0)) / $pageHeight, 2),
            'x2' => round(100 * ((float) ($line['x2'] ?? 0)) / $pageWidth, 2),
            'y2' => round(100 * ($pageHeight - (float) ($line['y2'] ?? 0)) / $pageHeight, 2),
        ];
        $out['mx'] = round(($out['x1'] + $out['x2']) / 2, 2);
        $out['my'] = round(($out['y1'] + $out['y2']) / 2, 2);
        if (is_string($line['role'] ?? null) && $line['role'] !== '') {
            $out['role'] = $line['role'];
        }
        if (is_string($line['kind'] ?? null) && $line['kind'] !== '') {
            $out['kind'] = $line['kind'];
        }
        if (is_string($line['axis'] ?? null) && $line['axis'] !== '') {
            $out['axis'] = $line['axis'];
        }
        if (is_string($line['side'] ?? null) && $line['side'] !== '') {
            $out['side'] = $line['side'];
        }
        if (is_string($line['caption'] ?? null) && $line['caption'] !== '') {
            $out['caption'] = $line['caption'];
        }

        return $out;
    }

    /**
     * @param  array{left: float, right: float, bottom: float, top: float, width: float, height: float}|null  $box
     * @return array{left: float, top: float, width: float, height: float}|null
     */
    private function overlayPercents(?array $box, float $pageWidth, float $pageHeight): ?array
    {
        if ($box === null || $pageWidth < 1 || $pageHeight < 1) {
            return null;
        }

        return [
            'left' => round(100 * ((float) $box['left']) / $pageWidth, 2),
            'width' => round(100 * ((float) $box['width']) / $pageWidth, 2),
            'top' => round(100 * ($pageHeight - (float) $box['top']) / $pageHeight, 2),
            'height' => round(100 * ((float) $box['height']) / $pageHeight, 2),
        ];
    }

    /**
     * Same placement and look as the calculation-board code chips: room centre, short code, material colour.
     *
     * @param  array<string, mixed>  $overlay
     * @param  array<string, mixed>  $boundary
     * @return array{x: float, y: float, text: string, bg: string, fg: string}
     */
    private function overlayChip(array $overlay, string $roomNumber, ?string $name, array $boundary, float $pageWidth, float $pageHeight): array
    {
        $center = $this->overlayChipCenter($overlay, $boundary, $pageWidth, $pageHeight);
        $bg = MaterialColor::fromCode($this->overlayChipMaterialCode($roomNumber, $name));

        return [
            'x' => $center['x'],
            'y' => $center['y'],
            'text' => $this->overlayChipText($name, $roomNumber),
            'bg' => $bg,
            'fg' => $this->overlayChipForeground($bg),
        ];
    }

    /**
     * @param  array<string, mixed>  $overlay
     * @param  array<string, mixed>  $boundary
     * @return array{x: float, y: float}
     */
    private function overlayChipCenter(array $overlay, array $boundary, float $pageWidth, float $pageHeight): array
    {
        $left = $boundary['left_pos'] ?? null;
        $right = $boundary['right_pos'] ?? null;
        $top = $boundary['top_pos'] ?? null;
        $bottom = $boundary['bottom_pos'] ?? null;
        $boxLeft = $overlay['left'] ?? null;
        $boxTop = $overlay['top'] ?? null;
        $boxWidth = $overlay['width'] ?? null;
        $boxHeight = $overlay['height'] ?? null;

        if (is_numeric($left) && is_numeric($right) && $pageWidth > 0) {
            $x = round(100 * (((float) $left + (float) $right) / 2) / $pageWidth, 2);
        } elseif (is_numeric($boxLeft) && is_numeric($boxWidth) && (float) $boxWidth > 0) {
            $x = round((float) $boxLeft + ((float) $boxWidth / 2), 2);
        } else {
            $x = (float) ($overlay['anchor']['x'] ?? 0);
        }

        if (is_numeric($top) && is_numeric($bottom) && $pageHeight > 0) {
            $y = round(100 * ($pageHeight - (((float) $top + (float) $bottom) / 2)) / $pageHeight, 2);
        } elseif (is_numeric($boxTop) && is_numeric($boxHeight) && (float) $boxHeight > 0) {
            $y = round((float) $boxTop + ((float) $boxHeight / 2), 2);
        } else {
            $y = (float) ($overlay['anchor']['y'] ?? 0);
        }

        return ['x' => $x, 'y' => $y];
    }

    private function overlayChipText(?string $name, string $roomNumber = ''): string
    {
        $label = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $name) ?? ''));
        if (preg_match('/^SLAAPKAMER\s+(\d+)$/u', $label, $match) === 1) {
            return 'S'.$match[1];
        }

        $compact = [
            'WOONKAMER' => 'WK',
            'WOONKEUKEN' => 'WKE',
            'KEUKEN' => 'KE',
            'BIJKEUKEN' => 'BK',
            'HAL' => 'HAL',
            'GANG' => 'GANG',
            'ENTREE' => 'ENT',
            'TOILET' => 'TOI',
            'WC' => 'WC',
            'BADKAMER' => 'BAD',
            'OPSLAG' => 'OPS',
            'BERGING' => 'BER',
            'KANTOOR' => 'KAN',
            'OVERLOOP' => 'OVL',
            'GARAGE' => 'GAR',
            'TECHNIEK' => 'TE',
            'ZOLDER' => 'ZOL',
            'KELDER' => 'KEL',
        ];
        if ($label !== '' && isset($compact[$label])) {
            return $compact[$label];
        }
        if ($label !== '') {
            return mb_substr(str_replace(' ', '', $label), 0, 4);
        }
        $number = trim($roomNumber);

        return $number !== '' ? $number : 'R';
    }

    private function overlayChipMaterialCode(string $roomNumber, ?string $name): string
    {
        $codes = ['v01.a', 'v01.b', 'v01.c', 'v01.d', 'v01.e', 'v01.f', 'v01.g', 'v02', 'v03', 'v04', 'v06', 'v08'];
        $index = (crc32(strtolower(trim($roomNumber.' '.$name))) & 0x7FFFFFFF) % count($codes);

        return $codes[$index];
    }

    private function overlayChipForeground(string $hex): string
    {
        $value = ltrim($hex, '#');
        if (! preg_match('/^[0-9a-f]{6}$/i', $value)) {
            return '#1c1917';
        }
        $red = hexdec(substr($value, 0, 2));
        $green = hexdec(substr($value, 2, 2));
        $blue = hexdec(substr($value, 4, 2));
        $luma = ((0.299 * $red) + (0.587 * $green) + (0.114 * $blue)) / 255;

        return $luma > 0.62 ? '#1c1917' : '#fff';
    }

    private function deviationLabel(?float $calculated, ?float $printed): string
    {
        if ($calculated === null || $printed === null) {
            return '—';
        }
        $abs = round($calculated - $printed, 2);
        $pct = $printed > 0 ? round(100 * ($calculated - $printed) / $printed, 2) : 0.0;
        $absLabel = ($abs > 0 ? '+' : '').Format::qty($abs, 2).' m²';
        $pctLabel = ($pct > 0 ? '+' : '').Format::qty($pct, 2).'%';

        return $absLabel.' / '.$pctLabel;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_RELIABLE => 'Groen – betrouwbaar',
            self::STATUS_REVIEW => 'Oranje – controleren',
            default => 'Rood – niet berekenbaar',
        };
    }

    /**
     * @param  array{x: float, y: float, page: int}  $anchor
     * @param  list<array{text: string, x: float, y: float, page: int}>  $names
     * @param  list<array{x: float, y: float, page: int, room_number?: string, room_key?: string}>  $anchors
     * @return array{text: string, x: float, y: float, page: int}|null
     */
    private function nearestNameItem(array $anchor, array $names, array $anchors, float $threshold): ?array
    {
        $best = null;
        $bestDistance = null;
        foreach ($names as $name) {
            if ((int) $name['page'] !== (int) $anchor['page']) {
                continue;
            }
            $distance = hypot((float) $name['x'] - (float) $anchor['x'], (float) $name['y'] - (float) $anchor['y']);
            if ($distance > $threshold || $this->closerToOtherAnchor($name, $anchor, $anchors, $distance)) {
                continue;
            }
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $name;
            }
        }

        return $best;
    }

    /**
     * @param  array{x: float, y: float, page: int}  $anchor
     * @param  list<array<string, mixed>>  $printed
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     */
    private function nearestPrinted(array $anchor, array $printed, array $anchors, float $threshold): ?float
    {
        $best = null;
        $bestDistance = null;
        foreach ($printed as $item) {
            if ((int) ($item['page'] ?? 0) !== (int) $anchor['page']) {
                continue;
            }
            $distance = hypot((float) $item['x'] - (float) $anchor['x'], (float) $item['y'] - (float) $anchor['y']);
            if ($distance > $threshold || $this->closerToOtherAnchor($item, $anchor, $anchors, $distance)) {
                continue;
            }
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = (float) $item['square_meters'];
            }
        }

        return $best;
    }

    /**
     * @param  array{x: float, y: float, page: int}  $candidate
     * @param  array{x: float, y: float, page: int, room_number?: string, room_key?: string}  $anchor
     * @param  list<array{x: float, y: float, page: int, room_number?: string, room_key?: string}>  $anchors
     */
    private function closerToOtherAnchor(array $candidate, array $anchor, array $anchors, float $distance): bool
    {
        $anchorKey = $anchor['room_key'] ?? $this->roomKey($anchor);
        foreach ($anchors as $other) {
            if ((int) $other['page'] !== (int) $anchor['page']) {
                continue;
            }
            $otherKey = $other['room_key'] ?? $this->roomKey($other);
            if ($otherKey === $anchorKey) {
                continue;
            }
            $otherDistance = hypot((float) $candidate['x'] - (float) $other['x'], (float) $candidate['y'] - (float) $other['y']);
            if ($otherDistance + 6 < $distance) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{x?: float, y?: float, page?: int, room_number?: string, text?: string}  $anchor
     */
    private function roomKey(array $anchor): string
    {
        $number = (string) ($anchor['room_number'] ?? '');
        if ($number !== '') {
            return 'nr:'.($anchor['page'] ?? 1).':'.$number;
        }

        return 'name:'.($anchor['page'] ?? 1).':'
            .round((float) ($anchor['x'] ?? 0)).':'
            .round((float) ($anchor['y'] ?? 0)).':'
            .(string) ($anchor['text'] ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $dimensions
     * @return list<array<string, mixed>>
     */
    private function uniqueDimensions(array $dimensions): array
    {
        $unique = [];
        foreach ($dimensions as $dimension) {
            $key = round((float) $dimension['x'], 1).'|'.round((float) $dimension['y'], 1).'|'.(int) $dimension['mm'];
            $unique[$key] = $dimension;
        }

        return array_values($unique);
    }

    /**
     * @param  list<array{text?: string}>  $texts
     */
    private function drawingScale(array $texts): ?int
    {
        foreach ($texts as $item) {
            $text = (string) ($item['text'] ?? '');
            if (preg_match('/schaal\s*[:.]?\s*1\s*:\s*(\d{1,4})\b/iu', $text, $match) === 1) {
                $scale = (int) $match[1];

                return $scale >= 10 && $scale <= 500 ? $scale : null;
            }
            if (preg_match('/^1\s*:\s*(\d{1,4})$/u', trim($text), $match) === 1) {
                $scale = (int) $match[1];

                return $scale >= 10 && $scale <= 500 ? $scale : null;
            }
        }

        return null;
    }

    private function nameFrom(string $text): ?string
    {
        $stripped = trim(preg_replace(
            '/\b[A-Z]-\d{2}-\d{2}\b|\b\d{1,2}[.\-]\d{2}[a-zA-Z]?\b|\d{3,5}\s*[x×]\s*\d{3,5}|\d{1,4}(?:[.,]\d+)?\s*m(?:²|2)\b|\d{3,5}(?:\s*mm)?/u',
            ' ',
            $text,
        ) ?? '');
        $stripped = trim(preg_replace('/\s+/u', ' ', $stripped) ?? '');
        if ($stripped === '' || ! preg_match('/^[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ0-9 \/,-]{1,40}$/u', $stripped)) {
            return null;
        }
        if ($this->isNoiseName($stripped) || preg_match('/^(?:v|pl|w|p)\d{2}/iu', $stripped)) {
            return null;
        }

        return $stripped;
    }

    private function isNoiseName(string $text): bool
    {
        return (bool) preg_match('/^(renvooi|legenda|disclaimer|schaal|noord|datum|formaat|getekend|project|adres|gebouw|verdieping|conform|minuten|brandwerend|m(?:²|2|¹|1)|mm)$/iu', $text);
    }

    private function isRoomName(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        return (bool) preg_match(self::ROOM_NAME_PATTERN, $text);
    }

    private function looksLikeRoomNumber(string $text): bool
    {
        return (bool) preg_match('/^[A-Z]-\d{2}-\d{2}$/u', $text)
            || (bool) preg_match('/^\d{1,2}[.\-]\d{2}[a-zA-Z]?$/u', $text);
    }

    private function isPrintedAreaToken(string $text): bool
    {
        return (bool) preg_match('/\d+(?:[.,]\d+)?\s*m(?:²|2)\b/u', $text)
            || (bool) preg_match('/^m(?:²|2)$/iu', $text);
    }

    private function isRoomSide(int $mm): bool
    {
        return $mm >= self::ROOM_SIDE_MIN_MM && $mm <= self::ROOM_SIDE_MAX_MM;
    }

    private function mmPairArea(int $a, int $b): ?float
    {
        $area = ($a / 1000) * ($b / 1000);
        if ($area < 0.4 || $area > 5000) {
            return null;
        }

        return round($area, 2);
    }

    private function pairTrace(int $a, int $b, float $area): string
    {
        return $a.' × '.$b.' → '.Format::qty($area, 2).' m²';
    }

    /**
     * @param  array{text: string, x: float, y: float, page: int}  $item
     */
    private function itemKey(array $item): string
    {
        return $item['page'].'|'.round((float) $item['x'], 1).'|'.round((float) $item['y'], 1).'|'.$item['text'];
    }

    private function pdfHasFontResource(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $size = filesize($path);
        if (! is_int($size) || $size < 1) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, min(524288, $size));
        $tail = '';
        if ($size > 524288) {
            fseek($handle, max(0, $size - 65536));
            $tail = (string) fread($handle, 65536);
        }
        fclose($handle);
        $blob = $head.$tail;

        return (bool) preg_match('/\/Type\s*\/Font\b/', $blob)
            || (bool) preg_match('/\/Font\s*<</', $blob);
    }

    /**
     * @return array{render: float, ocr: float, walls: float, rooms: float, matching: float, contours: float, extract: float, total: float}
     */
    private function resetTimings(): array
    {
        $this->timings = [
            'render' => 0.0,
            'ocr' => 0.0,
            'walls' => 0.0,
            'rooms' => 0.0,
            'matching' => 0.0,
            'contours' => 0.0,
            'extract' => 0.0,
            'total' => 0.0,
        ];
        $this->deadline = 0.0;

        return $this->timings;
    }

    private function secondsSince(int $started): float
    {
        return round((hrtime(true) - $started) / 1e9, 2);
    }

    private function secondsLeft(): float
    {
        if ($this->deadline <= 0) {
            return self::ANALYSIS_BUDGET_SECONDS;
        }

        return $this->deadline - microtime(true);
    }

    /**
     * @return array<string, string>
     */
    private function timingLabels(): array
    {
        $format = fn (float $seconds): string => Format::qty($seconds, 2).' s';

        return [
            'render' => $format($this->timings['render']),
            'ocr' => $format($this->timings['ocr']),
            'walls' => $format($this->timings['walls']),
            'rooms' => $format($this->timings['rooms']),
            'matching' => $format($this->timings['matching']),
            'contours' => $format($this->timings['contours']),
            'extract' => $format($this->timings['extract']),
            'total' => $format($this->timings['total']),
            'line' => 'render '.$format($this->timings['render'])
                .' | OCR '.$format($this->timings['ocr'])
                .' | lijn/wanddetectie '.$format($this->timings['walls'])
                .' | ruimteherkenning '.$format($this->timings['rooms'])
                .' | maatkoppeling '.$format($this->timings['matching'])
                .' | contouren '.$format($this->timings['contours'])
                .' | totaal '.$format($this->timings['total']),
        ];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniqueSortedStrings(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $unique[mb_strtoupper($value)] = $value;
        }
        ksort($unique);

        return array_values($unique);
    }

    /**
     * @param  list<int>  $values
     * @return list<int>
     */
    private function uniqueSortedInts(array $values): array
    {
        $unique = array_values(array_unique($values));
        sort($unique, SORT_NUMERIC);

        return $unique;
    }
}
