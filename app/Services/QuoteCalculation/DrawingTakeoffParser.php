<?php

namespace App\Services\QuoteCalculation;

use App\Enums\FinishRole;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Services\Meetstaat\PdfPageGeometry;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Support\DutchNumber;
use App\Support\Format;

class DrawingTakeoffParser
{
    /**
     * @param  list<DrawingFormatHandler>  $handlers
     */
    public function __construct(
        private PdfTextExtractor $extractor = new PdfTextExtractor,
        private DrawingTextPositions $positions = new DrawingTextPositions,
        private SpatialRoomAssembler $assembler = new SpatialRoomAssembler,
        private SpatialLegendAssembler $legendAssembler = new SpatialLegendAssembler,
        private SpatialFinishLinker $spatial = new SpatialFinishLinker,
        private FloorVariantResolver $variants = new FloorVariantResolver,
        private FinishPairingRules $pairing = new FinishPairingRules,
        private PlinthLengthCalculator $plinthLengths = new PlinthLengthCalculator,
        private PdfPageGeometry $geometry = new PdfPageGeometry,
        private array $handlers = [],
    ) {
        if ($this->handlers === []) {
            $this->handlers = [new GenericTextLayerHandler($this->plinthLengths)];
        }
    }

    /**
     * Register extra drawing formats without changing the rest of the module.
     */
    public function addHandler(DrawingFormatHandler $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * @return array{
     *     engine: string,
     *     handler: string,
     *     needs_ocr: bool,
     *     lines: list<array<string, mixed>>,
     *     legend: list<array{code: string, product: string, kind: string}>,
     *     warnings: list<string>
     * }
     */
    public function parseFile(string $path): array
    {
        $pages = $this->attachWalls($this->positions->extract($path), $path);
        $layout = $this->positions->layoutText($path);
        $engine = $layout !== '' ? 'pdftotext' : 'smalot';
        $text = $layout;
        if ($this->textTooWeak($text)) {
            $extracted = $this->extractor->extractDrawing($path);
            $text = $extracted['text'];
            $engine = $extracted['engine'];
        }

        $handler = $this->chooseHandler($text);
        $fromText = $handler->parse($text);
        $fromText['legend'] = $this->mergeLegend($fromText['legend'], $this->legendAssembler->assemble($pages));
        $fromSpatial = $this->assembler->assemble($pages, $fromText['legend']);
        $rooms = $this->mergeRooms($fromText['rooms'], $fromSpatial);
        $rooms = $this->spatial->link($rooms, $path, $pages);
        $rooms = $this->variants->resolve($rooms, $fromText['legend'], $pages);
        $rooms = $this->pairing->apply($rooms, $fromText['legend']);
        $rooms = $this->applyPlinthLengths($rooms, $pages);
        $rooms = $this->applyFloorOverlays($rooms, $pages);
        $fromText['rooms'] = $rooms;
        $fromText['warnings'] = $this->areaWarnings(
            $this->dropStaleTextWarnings($fromText['warnings'], $rooms, $fromText['legend']),
            $rooms,
            $text,
        );

        return $this->toLines($fromText, $handler->name(), $engine, $this->textTooWeak($text) && $rooms === []);
    }

    /**
     * @return array{
     *     engine: string,
     *     handler: string,
     *     needs_ocr: bool,
     *     lines: list<array<string, mixed>>,
     *     legend: list<array{code: string, product: string, kind: string}>,
     *     warnings: list<string>
     * }
     */
    public function parseText(string $text): array
    {
        $handler = $this->chooseHandler($text);
        $fromText = $handler->parse($text);
        $fromText['rooms'] = $this->pairing->apply($fromText['rooms'], $fromText['legend']);
        $fromText['rooms'] = $this->applyPlinthLengths($fromText['rooms'], []);

        return $this->toLines($fromText, $handler->name(), 'text', false);
    }

    private function chooseHandler(string $text): DrawingFormatHandler
    {
        $best = $this->handlers[0];
        $bestScore = $best->score($text);
        foreach ($this->handlers as $handler) {
            $score = $handler->score($text);
            if ($score > $bestScore) {
                $best = $handler;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function textTooWeak(string $text): bool
    {
        return mb_strlen(trim($text)) < 40;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function attachWalls(array $pages, string $path): array
    {
        $extracted = $this->geometry->extractWalls($path);
        if ($extracted === []) {
            return $pages;
        }

        $byPage = [];
        foreach ($extracted as $page) {
            $byPage[(int) ($page['page'] ?? 0)] = $page;
        }
        foreach ($pages as $index => $page) {
            $number = (int) ($page['page'] ?? $index + 1);
            if (! isset($byPage[$number])) {
                continue;
            }
            $pages[$index]['walls'] = $byPage[$number]['walls'] ?? [];
            $pages[$index]['geometry_width'] = $byPage[$number]['width'] ?? ($page['width'] ?? null);
            $pages[$index]['geometry_height'] = $byPage[$number]['height'] ?? ($page['height'] ?? null);
        }

        return $pages;
    }

    /**
     * @param  list<array<string, mixed>>  $fromText
     * @param  list<array<string, mixed>>  $fromSpatial
     * @return list<array<string, mixed>>
     */
    private function mergeRooms(array $fromText, array $fromSpatial): array
    {
        if ($fromSpatial === []) {
            return $fromText;
        }

        $merged = [];
        foreach ($fromText as $room) {
            $key = mb_strtolower((string) ($room['room_number'] ?? ''));
            if ($key !== '') {
                $merged[$key] = $room;
            }
        }
        foreach ($fromSpatial as $room) {
            $key = mb_strtolower((string) $room['room_number']);
            if (! isset($merged[$key])) {
                $merged[$key] = $room;

                continue;
            }
            $merged[$key] = $this->overlaySpatial($merged[$key], $room);
        }

        return array_values($merged);
    }

    /**
     * @param  list<array{code: string, product: string, kind: string}>  $fromText
     * @param  list<array{code: string, product: string, kind: string}>  $fromSpatial
     * @return list<array{code: string, product: string, kind: string}>
     */
    private function mergeLegend(array $fromText, array $fromSpatial): array
    {
        $merged = [];
        foreach ($fromText as $entry) {
            $merged[$entry['code']] = $entry;
        }
        foreach ($fromSpatial as $entry) {
            if (! isset($merged[$entry['code']])) {
                $merged[$entry['code']] = $entry;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $text
     * @param  array<string, mixed>  $spatial
     * @return array<string, mixed>
     */
    private function overlaySpatial(array $text, array $spatial): array
    {
        if (($spatial['floors'] ?? []) !== []) {
            $text['floors'] = $this->mergeFloorFinishes($text['floors'] ?? [], $spatial['floors']);
        }
        if (filled($spatial['floor_code'] ?? null)) {
            $text['floor_code'] = $spatial['floor_code'];
        }
        if (is_numeric($spatial['room_area'] ?? null) && ($text['room_area'] ?? null) === null) {
            $text['room_area'] = $spatial['room_area'];
        }
        if (filled($spatial['plinth_code'] ?? null)) {
            $text['plinth_code'] = $spatial['plinth_code'];
        }
        if (($text['room_name'] ?? null) === null && filled($spatial['room_name'] ?? null)) {
            $text['room_name'] = $spatial['room_name'];
        }
        if (($text['square_meters'] ?? null) === null && is_numeric($spatial['square_meters'] ?? null)) {
            $text['square_meters'] = $spatial['square_meters'];
        }
        if (filled($text['floor_code'] ?? null) && filled($text['room_name'] ?? null) && ($text['square_meters'] ?? null) !== null) {
            $text['needs_review'] = false;
        }

        return $text;
    }

    /**
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeFloorFinishes(array $current, array $incoming): array
    {
        if ($current === []) {
            return $incoming;
        }
        if ($incoming === []) {
            return $current;
        }

        $byCode = [];
        foreach ($current as $finish) {
            $code = mb_strtolower(trim((string) ($finish['code'] ?? '')));
            if ($code !== '') {
                $byCode[$code] = $finish;
            }
        }
        $merged = [];
        foreach ($incoming as $finish) {
            $code = mb_strtolower(trim((string) ($finish['code'] ?? '')));
            if ($code === '') {
                $merged[] = $finish;

                continue;
            }
            if (! isset($byCode[$code])) {
                $merged[] = $finish;

                continue;
            }
            $existing = $byCode[$code];
            if (($finish['quantity'] ?? null) === null && is_numeric($existing['quantity'] ?? null)) {
                $finish['quantity'] = $existing['quantity'];
            }
            $merged[] = $finish;
            unset($byCode[$code]);
        }
        foreach ($byCode as $finish) {
            if (($finish['role'] ?? '') === FinishRole::Main->value) {
                continue;
            }
            $finish['role'] = FinishRole::Local->value;
            $merged[] = $finish;
        }

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @param  list<array{page: int, width: float, height: float, texts: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @return list<array<string, mixed>>
     */
    private function applyPlinthLengths(array $rooms, array $pages): array
    {
        foreach ($rooms as $index => $room) {
            $floor = mb_strtolower(trim((string) ($room['floor_code'] ?? '')));
            $plinth = mb_strtolower(trim((string) ($room['plinth_code'] ?? '')));
            if ($floor !== FinishPairingRules::GIETVLOER
                && $plinth !== FinishPairingRules::HOLPLINT
                && $plinth !== FinishPairingRules::PLAKPLINT) {
                continue;
            }

            $result = $this->plinthLengths->forRoom($room, $pages);
            $hasTextMeters = is_numeric($room['plinth_meters'] ?? null)
                && in_array((string) ($room['plinth_source'] ?? ''), [
                    QuantitySource::FromDrawing->value,
                    QuantitySource::Calculated->value,
                ], true);
            if ($result['meters'] !== null && ! ($pages === [] && $hasTextMeters)) {
                $rooms[$index] = $this->applyPlinthResult($room, $result);

                continue;
            }

            $source = (string) ($room['plinth_source'] ?? '');
            if (is_numeric($room['plinth_meters'] ?? null) && $source === QuantitySource::FromDrawing->value) {
                continue;
            }

            if (is_numeric($room['plinth_meters'] ?? null) && $source === QuantitySource::Calculated->value) {
                if (! filled($room['plinth_trace'] ?? null)) {
                    $rooms[$index] = $this->applyPlinthResult(
                        $room,
                        $this->plinthLengths->result(
                            (float) $room['plinth_meters'],
                            [],
                            (float) $room['plinth_meters'],
                            true,
                        ),
                    );
                }

                continue;
            }

            $rooms[$index] = $this->applyPlinthResult($room, $result);
        }

        return $rooms;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function applyFloorOverlays(array $rooms, array $pages): array
    {
        if ($pages === []) {
            return $rooms;
        }

        foreach ($rooms as $index => $room) {
            $overlay = $this->plinthLengths->floorOverlay($room, $pages);
            if ($overlay !== null) {
                $rooms[$index]['floor_overlay'] = $overlay;
            }
        }

        return $rooms;
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function applyPlinthResult(array $room, array $result): array
    {
        $room['plinth_meters'] = $result['meters'];
        $room['plinth_source'] = $result['source'];
        $room['plinth_status'] = $result['status'];
        $room['note'] = $result['trace'];
        $room['plinth_trace'] = json_encode($result, JSON_UNESCAPED_UNICODE);

        return $room;
    }

    /**
     * @param  list<string>  $warnings
     * @param  list<array<string, mixed>>  $rooms
     * @return list<string>
     */
    private function areaWarnings(array $warnings, array $rooms, string $text): array
    {
        $sum = 0.0;
        $missing = 0;
        foreach ($rooms as $room) {
            if (is_numeric($room['square_meters'] ?? null)) {
                $sum += (float) $room['square_meters'];
            } else {
                $missing++;
            }
        }
        if ($missing > 0) {
            $warnings[] = $missing.' ruimte(s) zonder m².';
        }
        if (preg_match('/totaal\s+verdieping\s+(\d+(?:[.,]\d+)?)\s*m/iu', $text, $match)) {
            $declared = DutchNumber::parse($match[1]);
            if ($declared !== null && abs($declared - $sum) > 0.5) {
                $warnings[] = 'Som van uitgelezen ruimtes is '.Format::qty($sum, 2).' m²; op de tekening staat totaal '.Format::qty($declared, 2).' m².';
            }
        }

        return $warnings;
    }

    /**
     * @param  list<string>  $warnings
     * @param  list<array<string, mixed>>  $rooms
     * @param  list<array{code: string, product: string, kind: string}>  $legend
     * @return list<string>
     */
    private function dropStaleTextWarnings(array $warnings, array $rooms, array $legend): array
    {
        if ($rooms !== []) {
            $warnings = array_values(array_filter(
                $warnings,
                fn (string $warning) => ! str_contains($warning, 'Geen betrouwbare ruimtes gekoppeld'),
            ));
        }
        if ($legend !== []) {
            $warnings = array_values(array_filter(
                $warnings,
                fn (string $warning) => ! str_contains($warning, 'Geen legenda/renvooi'),
            ));
        }

        return $warnings;
    }

    /**
     * @param  array{rooms: list<array<string, mixed>>, legend: list<array{code: string, product: string, kind: string}>, warnings: list<string>}  $draft
     * @return array{
     *     engine: string,
     *     handler: string,
     *     needs_ocr: bool,
     *     lines: list<array<string, mixed>>,
     *     legend: list<array{code: string, product: string, kind: string}>,
     *     warnings: list<string>
     * }
     */
    private function toLines(array $draft, string $handler, string $engine, bool $needsOcr): array
    {
        $legendMap = [];
        foreach ($draft['legend'] as $entry) {
            $legendMap[mb_strtolower($entry['code'])] = $entry['product'];
        }

        $lines = [];
        foreach ($draft['rooms'] as $room) {
            $floorCode = $this->nullableString($room['floor_code'] ?? null);
            $squareMeters = is_numeric($room['square_meters'] ?? null) ? (float) $room['square_meters'] : null;
            $roomArea = is_numeric($room['room_area'] ?? null) ? (float) $room['room_area'] : $squareMeters;
            $name = $this->nullableString($room['room_name'] ?? null);
            $floorFinishes = $room['floors'] ?? [];
            if ($floorFinishes === []) {
                $floorFinishes = [[
                    'code' => $floorCode,
                    'quantity' => $squareMeters,
                    'role' => FinishRole::Main->value,
                ]];
            }

            foreach ($floorFinishes as $offset => $finish) {
                $code = $this->nullableString($finish['code'] ?? null);
                $quantity = is_numeric($finish['quantity'] ?? null) ? (float) $finish['quantity'] : null;
                $role = (string) ($finish['role'] ?? ($offset === 0 ? FinishRole::Main->value : FinishRole::Local->value));
                $floorSource = QuantitySource::FromDrawing;
                if ($quantity === null || $code === null || $name === null) {
                    $floorSource = QuantitySource::Review;
                }
                $note = null;
                if ($role === FinishRole::Local->value && $quantity === null) {
                    $note = 'Deelvlak zonder eigen m²; niet de volledige ruimteoppervlakte.';
                }

                $lines[] = $this->line(
                    $room,
                    $code,
                    $code === null ? null : ($legendMap[mb_strtolower($code)] ?? null),
                    $quantity,
                    WorkUnit::SquareMeter,
                    $floorSource,
                    $note,
                    $role,
                    $roomArea,
                    $this->finishTrace($room, $finish, $role),
                );
            }

            $plinthCode = $this->nullableString($room['plinth_code'] ?? null);
            if ($plinthCode !== null) {
                $plinthCode = mb_strtolower($plinthCode);
            }
            $plinthMeters = is_numeric($room['plinth_meters'] ?? null) ? (float) $room['plinth_meters'] : null;
            $trace = $this->nullableString($room['note'] ?? null);
            if ($plinthCode === null && $plinthMeters === null && $floorCode !== FinishPairingRules::GIETVLOER) {
                continue;
            }
            if ($floorCode === FinishPairingRules::GIETVLOER && $plinthCode === null) {
                $plinthCode = FinishPairingRules::HOLPLINT;
            }

            $plinthSource = $this->plinthSource($room, $plinthCode, $plinthMeters);
            $plinthNote = $trace;
            if ($plinthSource === QuantitySource::Review && $plinthMeters === null) {
                $plinthNote = $trace ?? 'Plintlengte niet betrouwbaar te bepalen; voer m¹ handmatig in.';
            }

            $lines[] = $this->line(
                $room,
                $plinthCode,
                FinishPairingRules::productFor(
                    (string) $plinthCode,
                    $legendMap,
                    $room['plinth_product'] ?? null,
                ),
                $plinthMeters,
                WorkUnit::LinearMeter,
                $plinthSource,
                $plinthNote === '' ? null : $plinthNote,
            );
        }

        $warnings = $draft['warnings'];
        if ($needsOcr && $lines === []) {
            $warnings[] = 'Deze tekening heeft geen betrouwbare tekstlaag. De PDF is bewaard; vul hoeveelheden handmatig in.';
        }

        return [
            'engine' => $engine,
            'handler' => $handler,
            'needs_ocr' => $needsOcr && $lines === [],
            'lines' => $lines,
            'legend' => $draft['legend'],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $room
     * @return array<string, mixed>
     */
    private function line(
        array $room,
        ?string $code,
        ?string $product,
        ?float $quantity,
        WorkUnit $unit,
        QuantitySource $source,
        ?string $note,
        ?string $finishRole = null,
        ?float $roomArea = null,
        ?string $trace = null,
    ): array {
        if ($code !== null && $product === null) {
            $source = QuantitySource::Review;
            $note = trim(($note ?? '').' Productcode niet in het renvooi gevonden.');
        }

        return [
            'room_number' => $this->nullableString($room['room_number'] ?? null),
            'room_name' => $this->nullableString($room['room_name'] ?? null),
            'product_code' => $code,
            'product' => $product,
            'quantity' => $quantity,
            'original_quantity' => $quantity,
            'original_product_code' => $code,
            'original_product' => $product,
            'unit' => $unit,
            'finish_role' => $unit === WorkUnit::SquareMeter ? $finishRole : null,
            'room_area' => $unit === WorkUnit::SquareMeter ? $roomArea : null,
            'source' => $source,
            'found_source' => $source instanceof QuantitySource ? $source->value : $source,
            'note' => $this->nullableString($note),
            'calculation_trace' => $trace ?? ($unit === WorkUnit::LinearMeter
                ? $this->nullableString($room['plinth_trace'] ?? $note)
                : null),
        ];
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  array<string, mixed>  $finish
     */
    private function finishTrace(array $room, array $finish, string $role): ?string
    {
        return $this->localFloorTrace($finish, $role) ?? $this->roomFloorTrace($room, $role);
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function roomFloorTrace(array $room, string $role): ?string
    {
        if ($role !== FinishRole::Main->value) {
            return null;
        }
        $overlay = $room['floor_overlay'] ?? null;
        if (! is_array($overlay) || ($overlay['reliable'] ?? false) !== true) {
            return null;
        }
        if (($overlay['rects'] ?? []) === []) {
            return null;
        }

        $encoded = json_encode($overlay, JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    /**
     * @param  array<string, mixed>  $finish
     */
    private function localFloorTrace(array $finish, string $role): ?string
    {
        if ($role !== FinishRole::Local->value) {
            return null;
        }

        $encoded = json_encode([
            'role' => 'local_floor',
            'page' => (int) ($finish['page'] ?? 1),
            'x' => (float) ($finish['x'] ?? 0),
            'y' => (float) ($finish['y'] ?? 0),
            'page_width' => (float) ($finish['page_width'] ?? 0),
            'page_height' => (float) ($finish['page_height'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{"role":"local_floor"}' : $encoded;
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function plinthSource(array $room, ?string $plinthCode, ?float $plinthMeters): QuantitySource
    {
        if ($plinthCode === null || $plinthMeters === null) {
            return QuantitySource::Review;
        }

        $raw = (string) ($room['plinth_source'] ?? QuantitySource::Review->value);
        if ($raw === QuantitySource::FromDrawing->value) {
            return QuantitySource::FromDrawing;
        }
        if ($raw === QuantitySource::Calculated->value) {
            return QuantitySource::Calculated;
        }

        return QuantitySource::Review;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? (string) $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }
}
