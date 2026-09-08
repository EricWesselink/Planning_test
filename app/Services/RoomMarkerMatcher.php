<?php

namespace App\Services;

use App\Models\AreaDrawingMarker;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Services\Meetstaat\FloorLabel;
use App\Services\Meetstaat\RoomIdentityResolver;
use App\Support\PdfTextNormalizer;

class RoomMarkerMatcher
{
    public function __construct(private RoomIdentityResolver $identity = new RoomIdentityResolver) {}

    /**
     * @param  list<array{page:int,text:string,x:float,y:float,w?:float,h?:float,source?:string}>  $items
     * @param  list<array{number?:string,page:int,x:float,y:float,w?:float,h?:float,label_text?:string,confidence?:float,source?:string}>  $rooms
     * @param  array{missing_only?: bool, drawing_rooms?: list<array<string, mixed>>}  $options
     * @return array{saved:int, skipped:int, unmatched:list<string>, review:list<string>, none:list<string>, unmatched_ids:list<int>, review_ids:list<int>, matches:list<array<string, mixed>>, counts:array{auto:int, manual:int, none:int, review:int, unmatched:int}}
     */
    public function match(Project $project, ProjectDocument $document, array $items, array $rooms = [], array $options = []): array
    {
        $project->loadMissing(['areas.floor', 'areas.markers']);
        $areas = $project->areas->values();
        $missingOnly = (bool) ($options['missing_only'] ?? false);

        $explicitPool = [];
        foreach ($rooms as $room) {
            $number = $this->normalize((string) ($room['number'] ?? ''));
            if ($number === '') {
                continue;
            }
            $explicitPool[] = $this->hitFromArray($room) + ['number' => $number];
        }

        $clusters = $this->clusterItems($items);
        $knownNumbers = $areas
            ->map(fn (ProjectArea $area) => $this->normalize((string) $area->displayNumber()))
            ->filter()
            ->values()
            ->all();

        $scored = [];
        foreach ($areas as $area) {
            $number = $this->normalize((string) $area->displayNumber());
            $name = (string) $area->displayName();
            foreach ($clusters as $index => $cluster) {
                $confidence = $this->score($number, $name, $cluster['text'], $knownNumbers);
                if ($confidence === null) {
                    continue;
                }
                $scored[] = [
                    'area' => $area,
                    'cluster' => $index,
                    'confidence' => $confidence,
                    'length' => mb_strlen($number),
                ];
            }
        }

        usort($scored, function (array $a, array $b) {
            return $b['confidence'] <=> $a['confidence']
                ?: $b['length'] <=> $a['length'];
        });

        $claimedAreas = [];
        $claimedClusters = [];
        $fromText = [];
        foreach ($scored as $row) {
            $areaId = $row['area']->id;
            $clusterIndex = $row['cluster'];
            if (isset($claimedAreas[$areaId]) || isset($claimedClusters[$clusterIndex])) {
                continue;
            }
            $claimedAreas[$areaId] = true;
            $claimedClusters[$clusterIndex] = true;
            $cluster = $clusters[$clusterIndex];
            $fromText[$areaId] = [
                'page' => $cluster['page'],
                'x' => $cluster['x'],
                'y' => $cluster['y'],
                'width' => $cluster['w'],
                'height' => $cluster['h'],
                'label_text' => $cluster['text'],
                'confidence' => $row['confidence'],
                'source' => $cluster['source'] ?? 'auto',
            ];
        }

        $drawingRooms = $this->drawingRoomsFromItems($items);
        foreach ($rooms as $room) {
            $drawingRooms[] = $this->drawingRoomFromExplicit($room);
        }
        foreach ($options['drawing_rooms'] ?? [] as $room) {
            $drawingRooms[] = $this->normalizeDrawingRoom($room);
        }
        $drawingRooms = $this->collapseNearbyDrawingRooms($drawingRooms);

        $usedDrawing = [];
        foreach ($fromText as $hit) {
            $this->claimNearbyDrawingRooms($hit, $drawingRooms, $usedDrawing);
        }

        $saved = 0;
        $skipped = 0;
        $none = [];
        $noneIds = [];
        $matches = [];
        $pending = [];
        $hits = [];
        $drawingRoomIds = [];

        foreach ($areas as $area) {
            $existing = AreaDrawingMarker::query()
                ->where('project_area_id', $area->id)
                ->where('project_document_id', $document->id)
                ->first();
            if ($existing && $existing->source === 'manual') {
                $skipped++;
                $matches[] = $this->matchRow($area, $existing->toBoardArray(), (string) ($existing->label_text ?? ''));

                continue;
            }
            if ($missingOnly && $existing && $existing->hasPosition()) {
                $skipped++;
                $matches[] = $this->matchRow($area, $existing->toBoardArray(), (string) ($existing->label_text ?? ''));

                continue;
            }

            $hit = $this->takeBestHit($area, $explicitPool)
                ?? $fromText[$area->id]
                ?? null;
            if ($hit === null) {
                $host = $this->areaIdentity($area, $drawingRooms);
                $resolved = $this->identity->match($host, $drawingRooms, $usedDrawing);
                if ($resolved !== null) {
                    $usedDrawing[$resolved['index']] = true;
                    $hit = $this->hitFromDrawingRoom($resolved['area']);
                    $hit['confidence'] = 1.0;
                    $drawingRoomIds[$area->id] = $resolved['area']['drawing_room_id'] ?? null;
                }
            }

            if ($hit === null) {
                $pending[] = $area;

                continue;
            }

            $hits[$area->id] = $hit;
            $drawingRoomIds[$area->id] = $drawingRoomIds[$area->id]
                ?? $hit['drawing_room_id']
                ?? null;
        }

        foreach ($this->bestCandidateHits($pending, $drawingRooms, $usedDrawing) as $areaId => $hit) {
            $hits[$areaId] = $hit;
            $drawingRoomIds[$areaId] = $hit['drawing_room_id'] ?? null;
        }

        foreach ($areas as $area) {
            if (! isset($hits[$area->id])) {
                if ($this->alreadyReported($area, $matches)) {
                    continue;
                }
                $none[] = trim(($area->displayNumber() ?? '').' '.$area->displayName());
                $noneIds[] = $area->id;

                continue;
            }

            $hit = $hits[$area->id];
            $source = in_array($hit['source'] ?? '', ['text', 'ocr', 'auto'], true)
                ? $hit['source']
                : 'auto';
            $marker = AreaDrawingMarker::query()->updateOrCreate(
                [
                    'project_area_id' => $area->id,
                    'project_document_id' => $document->id,
                ],
                [
                    'page' => $hit['page'],
                    'x' => $hit['x'],
                    'y' => $hit['y'],
                    'width' => $hit['width'],
                    'height' => $hit['height'],
                    'label_text' => $hit['label_text'],
                    'polygon' => $hit['polygon'] ?? $this->boxPolygon($hit),
                    'confidence' => $hit['confidence'],
                    'source' => $source,
                    'drawing_room_id' => $drawingRoomIds[$area->id] ?? $hit['drawing_room_id'] ?? null,
                ]
            );

            $saved++;
            $row = $this->matchRow($area, $marker->toBoardArray(), (string) ($hit['label_text'] ?? ''));
            $row['drawing_room_id'] = $marker->drawing_room_id ?: $marker->id;
            $row['confidence_label'] = $marker->confidenceLabel();
            $matches[] = $row;
        }

        $this->dropCollidingAutoMarkers($document);

        $linked = $saved + $skipped;

        return [
            'saved' => $saved,
            'skipped' => $skipped,
            'unmatched' => $none,
            'review' => [],
            'unmatched_ids' => $noneIds,
            'review_ids' => [],
            'none' => $none,
            'matches' => $matches,
            'counts' => [
                'auto' => $linked,
                'manual' => 0,
                'none' => count($none),
                'review' => 0,
                'unmatched' => count($none),
            ],
        ];
    }

    /**
     * @param  list<string>  $knownNumbers
     */
    public function score(string $number, string $name, string $text, array $knownNumbers = []): ?float
    {
        $number = $this->normalize($number);
        if ($number === '') {
            return null;
        }

        $recovered = $this->recoveredNumbers($text);
        if (! in_array($number, $recovered, true) && ! in_array($this->coreNumber($number), $recovered, true)) {
            return null;
        }

        foreach ($recovered as $token) {
            if ($this->isMoreSpecific($token, $number) && in_array($token, $knownNumbers, true)) {
                return null;
            }
            if ($this->isMoreSpecific($token, $number) && $this->looksLikeRoomNumber($token)) {
                return null;
            }
        }

        if (! in_array($number, $recovered, true)) {
            $core = $this->coreNumber($number);
            $coreHits = 0;
            foreach ($knownNumbers as $known) {
                if ($this->coreNumber($known) === $core) {
                    $coreHits++;
                }
            }
            if ($coreHits > 1) {
                return null;
            }
        }

        return $this->nameMatches($name, $text) || $this->normalize($text) === $number
            ? 1.0
            : 0.82;
    }

    public function confidence(string $number, string $text): ?float
    {
        return $this->score($number, '', $text);
    }

    /**
     * @return list<string>
     */
    public function recoveredNumbers(string $text): array
    {
        $found = [];
        foreach ($this->textVariants($text) as $variant) {
            foreach ($this->tokens($variant) as $token) {
                $found[$this->normalize($token)] = true;
            }
            if (preg_match_all('/(?<![0-9a-z])((?:[a-z]\.\s*)?\d+\.\d+[a-z]?)(?![0-9a-z])/u', $variant, $matches)) {
                foreach ($matches[1] as $raw) {
                    $found[$this->normalize($raw)] = true;
                    $core = $this->coreNumber($raw);
                    if ($core !== $this->normalize($raw)) {
                        $found[$core] = true;
                    }
                }
            }
            if (preg_match_all('/[0-9]+(?:\.[0-9]+)+(?:[a-z](?![a-z]))?/u', $variant, $matches)) {
                foreach ($matches[0] as $raw) {
                    foreach ($this->compactDotted($raw) as $candidate) {
                        $found[$this->normalize($candidate)] = true;
                    }
                }
            }
        }

        $tokens = array_values(array_filter(array_keys($found)));
        $kept = [];
        foreach ($tokens as $token) {
            $hasLonger = false;
            foreach ($tokens as $other) {
                if ($other !== $token && $this->isMoreSpecific($other, $token) && $this->looksLikeRoomNumber($other)) {
                    $hasLonger = true;
                    break;
                }
            }
            if (! $hasLonger) {
                $kept[] = $token;
            }
        }

        return $kept;
    }

    public function nameMatches(string $expected, string $text): bool
    {
        $expectedLetters = $this->lettersOnly($expected);
        $textLetters = $this->lettersOnly($text);
        if ($expectedLetters === '' || $textLetters === '') {
            return false;
        }
        if (str_contains($textLetters, $expectedLetters)) {
            return true;
        }
        $collapsed = $this->collapseRuns($textLetters);
        if (str_contains($collapsed, $expectedLetters)) {
            return true;
        }
        $undoubled = $this->pairwiseUndouble($textLetters);
        if (str_contains($undoubled, $expectedLetters)) {
            return true;
        }
        if (mb_strlen($expectedLetters) < 4) {
            return false;
        }

        return mb_strlen($textLetters) <= (int) ceil(mb_strlen($expectedLetters) * 2.6)
            && $this->isSubsequence($expectedLetters, $textLetters);
    }

    /**
     * @return list<string>
     */
    public function tokens(string $text): array
    {
        $source = mb_strtolower(str_replace(',', '.', $text));
        preg_match_all('/(?:[a-z]\.\s*)?[0-9]+(?:\.[0-9]+)+(?:[a-z](?![a-z]))?/u', $source, $matches);

        return $matches[0] ?? [];
    }

    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(',', '.', $value);

        return preg_replace('/\s+/', '', $value) ?? $value;
    }

    public function coreNumber(string $value): string
    {
        $number = $this->normalize($value);
        if (preg_match('/^[a-z]\.\d+\.\d+[a-z]?$/u', $number) === 1) {
            return mb_substr($number, 2);
        }

        return $number;
    }

    /**
     * @param  list<array{page:int,text:string,x:float,y:float,w?:float,h?:float}>  $items
     * @return list<array{page:int,text:string,x:float,y:float,w:float,h:float}>
     */
    public function clusterItems(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $w = max(0.008, (float) ($item['w'] ?? $item['width'] ?? 0.02));
            $h = max(0.008, (float) ($item['h'] ?? $item['height'] ?? 0.014));
            $x = $this->clamp((float) ($item['x'] ?? 0));
            $y = $this->clamp((float) ($item['y'] ?? 0));
            $source = $item['source'] ?? 'auto';
            $rows[] = [
                'page' => max(1, (int) ($item['page'] ?? 1)),
                'text' => $text,
                'x' => $x,
                'y' => $y,
                'w' => $this->clamp($w),
                'h' => $this->clamp($h),
                'source' => in_array($source, ['text', 'ocr', 'auto'], true) ? $source : 'auto',
            ];
        }

        usort($rows, fn ($a, $b) => $a['page'] <=> $b['page'] ?: $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']);

        $clusters = [];
        foreach ($rows as $row) {
            $prev = $clusters === [] ? null : $clusters[array_key_last($clusters)];
            $sameLine = false;
            if ($prev) {
                $prevRight = $prev['x'] + $prev['w'];
                $sameLine = $prev['page'] === $row['page']
                    && abs(($prev['y'] + $prev['h'] / 2) - ($row['y'] + $row['h'] / 2)) < 0.01
                    && $row['x'] >= $prevRight - 0.008
                    && $row['x'] <= $prevRight + 0.028;
            }
            if ($sameLine) {
                $right = max($prev['x'] + $prev['w'], $row['x'] + $row['w']);
                $bottom = max($prev['y'] + $prev['h'], $row['y'] + $row['h']);
                $left = min($prev['x'], $row['x']);
                $top = min($prev['y'], $row['y']);
                $clusters[array_key_last($clusters)] = [
                    'page' => $prev['page'],
                    'text' => trim($prev['text'].' '.$row['text']),
                    'x' => $left,
                    'y' => $top,
                    'w' => max(0.012, $right - $left),
                    'h' => max(0.01, $bottom - $top),
                    'source' => $prev['source'] ?? 'auto',
                ];
            } else {
                $clusters[] = $row;
            }
        }

        return $clusters;
    }

    /**
     * @return list<string>
     */
    private function textVariants(string $text): array
    {
        $normalized = $this->normalize($text);

        return array_values(array_unique(array_filter([
            $text,
            mb_strtolower(str_replace(',', '.', $text)),
            $normalized,
            $this->collapseRuns($normalized),
            $this->pairwiseUndouble($normalized),
        ])));
    }

    /**
     * @return list<string>
     */
    private function compactDotted(string $raw): array
    {
        $out = [$raw, $this->collapseRuns($raw)];
        $candidates = $out;
        foreach ($candidates as $value) {
            $value = $this->normalize($value);
            if (preg_match('/^(\d+)\.(?:\d+\.)+(\d+)([a-z]?)$/u', $value, $match)) {
                $left = ltrim($match[1], '0');
                $right = ltrim($match[2], '0');
                $out[] = ($left === '' ? '0' : $left).'.'.($right === '' ? '0' : $right).$match[3];
                $out[] = $match[1].'.'.$match[2].$match[3];
            }
        }

        return $out;
    }

    private function collapseRuns(string $value): string
    {
        return preg_replace('/(.)\1+/u', '$1', $value) ?? $value;
    }

    private function pairwiseUndouble(string $value): string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($chars);
        if ($count < 4) {
            return $value;
        }
        $pairs = 0;
        $same = 0;
        $even = '';
        for ($i = 0; $i < $count - 1; $i += 2) {
            $pairs++;
            if ($chars[$i] === $chars[$i + 1]) {
                $same++;
            }
            $even .= $chars[$i];
        }
        if ($count % 2 === 1) {
            $even .= $chars[$count - 1];
        }

        return ($pairs > 0 && ($same / $pairs) >= 0.55) ? $even : $value;
    }

    private function lettersOnly(string $value): string
    {
        return preg_replace('/[^a-z]/u', '', mb_strtolower($value)) ?? '';
    }

    private function isSubsequence(string $needle, string $haystack): bool
    {
        $n = 0;
        $nLen = mb_strlen($needle);
        $hLen = mb_strlen($haystack);
        for ($i = 0; $i < $hLen && $n < $nLen; $i++) {
            if (mb_substr($haystack, $i, 1) === mb_substr($needle, $n, 1)) {
                $n++;
            }
        }

        return $n === $nLen;
    }

    private function isMoreSpecific(string $token, string $number): bool
    {
        if ($token === $number || ! str_starts_with($token, $number)) {
            return false;
        }
        $extra = substr($token, strlen($number));

        return $extra !== '' && preg_match('/^[0-9a-z]/u', $extra) === 1;
    }

    private function looksLikeRoomNumber(string $token): bool
    {
        return preg_match('/^(?:[a-z]\.)?\d+\.\d+[a-z]?$/u', $token) === 1
            && ! preg_match('/[a-z]{2}/u', $token);
    }

    /**
     * @param  list<array{number:string,page:int,x:float,y:float,width:float,height:float,label_text:?string,confidence:float,source:string}>  $pool
     * @return array{page:int,x:float,y:float,width:float,height:float,label_text:?string,confidence:float,source:string}|null
     */
    private function takeBestHit(ProjectArea $area, array &$pool): ?array
    {
        $number = $this->normalize((string) $area->displayNumber());
        $raw = $this->normalize((string) $area->area_number);
        $name = (string) $area->displayName();
        $bestIndex = null;
        $bestScore = -1.0;
        foreach ($pool as $index => $hit) {
            $hitNumber = $this->normalize((string) ($hit['number'] ?? ''));
            if ($hitNumber !== $number && ($raw === '' || $hitNumber !== $raw)) {
                continue;
            }
            $score = $this->hitNameScore($name, (string) ($hit['label_text'] ?? ''));
            if ($score === null) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }
        if ($bestIndex === null) {
            return null;
        }
        $hit = $pool[$bestIndex];
        unset($pool[$bestIndex]);
        $pool = array_values($pool);
        unset($hit['number']);

        return $hit;
    }

    private function hitNameScore(string $name, string $label): ?float
    {
        if ($this->nameMatches($name, $label)) {
            return 2.0 + min(1.0, mb_strlen($this->lettersOnly($name)) / 12);
        }
        if ($this->lettersOnly($label) === '') {
            return 0.4;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $room
     * @return array{page:int,text:string,x:float,y:float,w:float,h:float,source:string}
     */
    private function clusterFromRoom(array $room): array
    {
        $hit = $this->hitFromArray($room);
        $text = trim((string) ($room['label_text'] ?? $room['number'] ?? ''));

        return [
            'page' => $hit['page'],
            'text' => $text,
            'x' => $hit['x'],
            'y' => $hit['y'],
            'w' => $hit['width'],
            'h' => $hit['height'],
            'source' => $hit['source'],
        ];
    }

    /**
     * @param  list<array{page:int,text:string,x:float,y:float,w:float,h:float,source?:string}>  $clusters
     * @return list<array{page:int,text:string,x:float,y:float,w:float,h:float,source?:string}>
     */
    private function dedupeNearClusters(array $clusters): array
    {
        $kept = [];
        foreach ($clusters as $cluster) {
            $merged = false;
            foreach ($kept as $index => $existing) {
                if ($existing['page'] !== $cluster['page']) {
                    continue;
                }
                if (abs($existing['x'] - $cluster['x']) > 0.015 || abs($existing['y'] - $cluster['y']) > 0.015) {
                    continue;
                }
                if (! $this->shareRecoveredNumber($existing['text'], $cluster['text'])) {
                    continue;
                }
                if (mb_strlen($cluster['text']) > mb_strlen($existing['text'])) {
                    $kept[$index] = $cluster;
                }
                $merged = true;
                break;
            }
            if (! $merged) {
                $kept[] = $cluster;
            }
        }

        return array_values($kept);
    }

    private function shareRecoveredNumber(string $left, string $right): bool
    {
        return count(array_intersect($this->recoveredNumbers($left), $this->recoveredNumbers($right))) > 0;
    }

    private function dropCollidingAutoMarkers(ProjectDocument $document): void
    {
        $markers = AreaDrawingMarker::query()
            ->where('project_document_id', $document->id)
            ->with('area')
            ->get();

        $groups = $markers->groupBy(fn (AreaDrawingMarker $marker) => sprintf(
            '%d|%.4f|%.4f',
            (int) $marker->page,
            round((float) $marker->x, 4),
            round((float) $marker->y, 4),
        ));

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }
            $names = $group
                ->map(fn (AreaDrawingMarker $marker) => $this->matchName((string) ($marker->area?->displayName() ?? '')))
                ->filter()
                ->unique()
                ->values();
            if ($names->count() <= 1) {
                continue;
            }
            $keepId = $group->sortByDesc(function (AreaDrawingMarker $marker) {
                $manual = $marker->source === 'manual' ? 2 : 0;
                $named = $this->nameMatches((string) $marker->area?->displayName(), (string) $marker->label_text) ? 1 : 0;

                return $manual + $named;
            })->first()?->id;

            foreach ($group as $marker) {
                if ($marker->id !== $keepId && $marker->source !== 'manual') {
                    $marker->delete();
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $room
     * @return array{page:int,x:float,y:float,width:float,height:float,label_text:?string,confidence:float,source:string}
     */
    private function hitFromArray(array $room): array
    {
        $label = isset($room['label_text']) ? mb_substr(trim((string) $room['label_text']), 0, 160) : null;
        $fromText = $label ? max(0.05, min(0.28, mb_strlen($label) * 0.005)) : 0.05;
        $width = max(0.05, (float) ($room['w'] ?? $room['width'] ?? 0.08), $fromText);
        $height = max(0.016, (float) ($room['h'] ?? $room['height'] ?? 0.018));
        $source = $room['source'] ?? 'auto';
        if (! in_array($source, ['text', 'ocr', 'auto'], true)) {
            $source = 'auto';
        }

        return [
            'page' => max(1, (int) ($room['page'] ?? 1)),
            'x' => $this->clamp((float) ($room['x'] ?? 0)),
            'y' => $this->clamp((float) ($room['y'] ?? 0)),
            'width' => $this->clamp($width),
            'height' => $this->clamp($height),
            'label_text' => $label,
            'confidence' => $this->clamp((float) ($room['confidence'] ?? ($source === 'ocr' ? 0.72 : 1.0))),
            'source' => $source,
        ];
    }

    /**
     * @param  array{x:float,y:float,width:float,height:float}  $hit
     * @return list<array{x:float,y:float}>
     */
    private function boxPolygon(array $hit): array
    {
        $x = $hit['x'];
        $y = $hit['y'];
        $w = $hit['width'];
        $h = $hit['height'];

        return [
            ['x' => $x, 'y' => $y],
            ['x' => $this->clamp($x + $w), 'y' => $y],
            ['x' => $this->clamp($x + $w), 'y' => $this->clamp($y + $h)],
            ['x' => $x, 'y' => $this->clamp($y + $h)],
        ];
    }

    /**
     * @param  array<string, mixed>  $marker
     * @return array<string, mixed>
     */
    private function matchRow(ProjectArea $area, array $marker, string $found): array
    {
        return [
            'area_id' => $area->id,
            'number' => $area->displayNumber(),
            'name' => $area->displayName(),
            'found_text' => $marker['label_text'] ?? $found,
            'page' => $marker['page'] ?? null,
            'x' => $marker['x'] ?? null,
            'y' => $marker['y'] ?? null,
            'width' => $marker['width'] ?? null,
            'height' => $marker['height'] ?? null,
            'confidence' => $marker['confidence'] ?? null,
            'source' => $marker['source'] ?? 'auto',
            'found_via' => $marker['source'] ?? 'auto',
        ];
    }

    private function clamp(float $value): float
    {
        return max(0, min(1, round($value, 6)));
    }

    /**
     * @param  list<array{page:int,text:string,x:float,y:float,w?:float,h?:float,source?:string}>  $items
     * @return list<array<string, mixed>>
     */
    public function drawingRoomsFromItems(array $items): array
    {
        $clusters = $this->attachSplitSquareMeters($this->clusterItems($items));
        $pageFloors = $this->pageFloors($clusters);
        $meters = [];
        $names = [];
        foreach ($clusters as $index => $cluster) {
            $squareMeters = PdfTextNormalizer::extractSquareMeters($cluster['text']);
            $roomName = $this->roomNameFromText($cluster['text']);
            $numbers = $this->recoveredNumbers($cluster['text']);
            if ($squareMeters !== null) {
                $meters[] = $cluster + [
                    'index' => $index,
                    'square_meters' => $squareMeters,
                    'numbers' => $numbers,
                    'room_name' => $roomName,
                ];
            }
            if ($roomName !== null) {
                $names[] = $cluster + [
                    'index' => $index,
                    'room_name' => $roomName,
                    'numbers' => $numbers,
                ];
            }
        }

        $rooms = [];
        foreach ($meters as $meter) {
            $best = null;
            $bestDist = 999.0;
            foreach ($names as $nameIndex => $name) {
                if ((int) $name['page'] !== (int) $meter['page']) {
                    continue;
                }
                $dx = abs((float) $name['x'] - (float) $meter['x']);
                $dy = abs((float) $meter['y'] - (float) $name['y']);
                if ($dx > 0.12 || $dy > 0.08) {
                    continue;
                }
                $dist = hypot($dx, $dy);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $best = $nameIndex;
                }
            }
            $name = $best !== null ? $names[$best] : null;
            $roomName = (string) ($name['room_name'] ?? $meter['room_name'] ?? '');
            $numbers = $meter['numbers'] !== [] ? $meter['numbers'] : ($name['numbers'] ?? []);
            if ($roomName === '' && $numbers === []) {
                continue;
            }
            $anchor = $name ?? $meter;
            $rooms[] = $this->normalizeDrawingRoom([
                'drawing_room_id' => sprintf('p%d-%.4f-%.4f', $meter['page'], $meter['x'], $meter['y']),
                'page' => $meter['page'],
                'floor' => $pageFloors[$meter['page']] ?? 'Onbekend',
                'room_number' => $numbers[0] ?? null,
                'room_name' => $roomName,
                'square_meters' => $meter['square_meters'],
                'x' => $anchor['x'],
                'y' => $anchor['y'],
                'width' => $anchor['w'] ?? $anchor['width'] ?? 0.08,
                'height' => $anchor['h'] ?? $anchor['height'] ?? 0.018,
                'label_text' => trim($roomName.' '.$meter['text']),
                'source' => $meter['source'] ?? 'auto',
            ]);
        }

        return $rooms;
    }

    /**
     * @param  list<array<string, mixed>>  $clusters
     * @return list<array<string, mixed>>
     */
    private function attachSplitSquareMeters(array $clusters): array
    {
        foreach ($clusters as $index => $cluster) {
            if (PdfTextNormalizer::extractSquareMeters((string) ($cluster['text'] ?? '')) !== null) {
                continue;
            }
            if (preg_match('/^\d+(?:[.,]\d+)?$/u', trim((string) ($cluster['text'] ?? ''))) !== 1) {
                continue;
            }
            foreach ($clusters as $other) {
                if ((int) ($other['page'] ?? 0) !== (int) ($cluster['page'] ?? 0)) {
                    continue;
                }
                if (preg_match('/^m(?:²|2)$/iu', trim((string) ($other['text'] ?? ''))) !== 1) {
                    continue;
                }
                $dx = (float) ($other['x'] ?? 0) - (float) ($cluster['x'] ?? 0);
                $dy = abs((float) ($other['y'] ?? 0) - (float) ($cluster['y'] ?? 0));
                if ($dx < -0.01 || $dx > 0.06 || $dy > 0.02) {
                    continue;
                }
                $clusters[$index]['text'] = trim((string) $cluster['text'].' m²');
                break;
            }
        }

        return $clusters;
    }

    /**
     * @param  array<string, mixed>  $room
     * @return array<string, mixed>
     */
    private function drawingRoomFromExplicit(array $room): array
    {
        $label = trim((string) ($room['label_text'] ?? $room['number'] ?? ''));

        return $this->normalizeDrawingRoom([
            'drawing_room_id' => sprintf(
                'p%d-%.4f-%.4f',
                (int) ($room['page'] ?? 1),
                (float) ($room['x'] ?? 0),
                (float) ($room['y'] ?? 0),
            ),
            'page' => $room['page'] ?? 1,
            'floor' => $room['floor'] ?? 'Onbekend',
            'room_number' => $room['number'] ?? $room['room_number'] ?? null,
            'room_name' => $this->roomNameFromText($label) ?? '',
            'square_meters' => PdfTextNormalizer::extractSquareMeters($label),
            'x' => $room['x'] ?? 0,
            'y' => $room['y'] ?? 0,
            'width' => $room['w'] ?? $room['width'] ?? 0.08,
            'height' => $room['h'] ?? $room['height'] ?? 0.018,
            'label_text' => $label,
            'source' => $room['source'] ?? 'auto',
            'polygon' => $room['polygon'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $room
     * @return array<string, mixed>
     */
    public function normalizeDrawingRoom(array $room): array
    {
        $page = max(1, (int) ($room['page'] ?? 1));
        $x = $this->clamp((float) ($room['x'] ?? 0));
        $y = $this->clamp((float) ($room['y'] ?? 0));
        $width = $this->clamp(max(0.02, (float) ($room['width'] ?? $room['w'] ?? 0.08)));
        $height = $this->clamp(max(0.012, (float) ($room['height'] ?? $room['h'] ?? 0.018)));
        $name = trim((string) ($room['room_name'] ?? ''));
        $number = trim((string) ($room['room_number'] ?? $room['number'] ?? ''));
        $label = trim((string) ($room['label_text'] ?? ($number.' '.$name)));
        $id = (string) ($room['drawing_room_id'] ?? $room['contour_id'] ?? '');
        if ($id === '') {
            $id = sprintf('p%d-%.4f-%.4f', $page, $x, $y);
        }

        return [
            'drawing_room_id' => $id,
            'contour_id' => $room['contour_id'] ?? $id,
            'page' => $page,
            'floor' => $this->canonicalFloor((string) ($room['floor'] ?? 'Onbekend')),
            'room_number' => $number !== '' ? $number : null,
            'room_name' => $name,
            'square_meters' => isset($room['square_meters']) ? (float) $room['square_meters'] : null,
            'x' => $x,
            'y' => $y,
            'meter_x' => $room['meter_x'] ?? $x,
            'meter_y' => $room['meter_y'] ?? $y,
            'width' => $width,
            'height' => $height,
            'label_text' => mb_substr($label, 0, 160),
            'source' => in_array($room['source'] ?? '', ['text', 'ocr', 'auto'], true) ? $room['source'] : 'auto',
            'polygon' => $room['polygon'] ?? null,
            'confidence' => (float) ($room['confidence'] ?? 0.9),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $drawingRooms
     * @return array<string, mixed>
     */
    private function areaIdentity(ProjectArea $area, array $drawingRooms): array
    {
        $floor = $this->canonicalFloor((string) ($area->floor?->name ?? ''));
        $pages = [];
        $floors = new FloorLabel;
        foreach ($drawingRooms as $room) {
            $roomFloor = (string) ($room['floor'] ?? '');
            if ($floor !== '' && $roomFloor !== '' && ! $floors->sameStorey($floor, $roomFloor)) {
                continue;
            }
            $page = (int) ($room['page'] ?? 0);
            if ($page > 0) {
                $pages[$page] = true;
            }
        }

        return [
            'floor' => $floor,
            'page' => count($pages) === 1 ? array_key_first($pages) : null,
            'room_number' => $area->displayNumber(),
            'room_name' => $area->displayName(),
            'square_meters' => $area->square_meters !== null ? (float) $area->square_meters : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $room
     * @return array{page:int,x:float,y:float,width:float,height:float,label_text:?string,confidence:float,source:string,drawing_room_id?:string,polygon?:list<array{x:float,y:float}>|null}
     */
    private function hitFromDrawingRoom(array $room): array
    {
        $normalized = $this->normalizeDrawingRoom($room);
        $hit = $this->hitFromArray($normalized);
        $hit['drawing_room_id'] = $normalized['drawing_room_id'];
        if (is_array($normalized['polygon'] ?? null)) {
            $hit['polygon'] = $normalized['polygon'];
        }

        return $hit;
    }

    /**
     * @param  array{page:int,x:float,y:float}  $hit
     * @param  list<array<string, mixed>>  $drawingRooms
     * @param  array<int, true>  $usedDrawing
     */
    private function claimNearbyDrawingRooms(array $hit, array $drawingRooms, array &$usedDrawing): void
    {
        $hitNumbers = $this->recoveredNumbers((string) ($hit['label_text'] ?? ''));
        foreach ($drawingRooms as $index => $room) {
            if (isset($usedDrawing[$index])) {
                continue;
            }
            if ((int) ($room['page'] ?? 0) !== (int) $hit['page']) {
                continue;
            }
            $roomNumber = $this->normalize((string) ($room['room_number'] ?? ''));
            if ($roomNumber === '' || $hitNumbers === [] || ! in_array($roomNumber, $hitNumbers, true)) {
                continue;
            }
            $dx = (float) ($room['x'] ?? 0) - (float) $hit['x'];
            $dy = (float) ($room['y'] ?? 0) - (float) $hit['y'];
            if (hypot($dx, $dy) <= 0.03) {
                $usedDrawing[$index] = true;
            }
        }
    }

    /**
     * @param  list<ProjectArea>  $areas
     * @param  list<array<string, mixed>>  $drawingRooms
     * @param  array<int, true>  $usedDrawing
     * @return array<int, array<string, mixed>>
     */
    private function bestCandidateHits(array $areas, array $drawingRooms, array &$usedDrawing): array
    {
        $hits = [];
        if ($areas === []) {
            return $hits;
        }

        $passes = ['storey|name|meters', 'storey|name'];
        foreach ($passes as $pass) {
            $areaBuckets = [];
            foreach ($areas as $area) {
                if (isset($hits[$area->id])) {
                    continue;
                }
                $areaBuckets[$this->candidateKey($area, $pass)][] = $area;
            }
            $roomBuckets = [];
            foreach ($drawingRooms as $index => $room) {
                if (isset($usedDrawing[$index])) {
                    continue;
                }
                $roomBuckets[$this->drawingCandidateKey($room, $pass)][] = $index;
            }

            foreach ($areaBuckets as $key => $group) {
                if (str_contains($key, '|?') || $key === '') {
                    continue;
                }
                $roomIndexes = $roomBuckets[$key] ?? [];
                $roomIndexes = array_values(array_filter(
                    $roomIndexes,
                    fn (int $index) => ! isset($usedDrawing[$index])
                ));
                if ($roomIndexes === [] || $group === []) {
                    continue;
                }
                usort($roomIndexes, fn (int $left, int $right) => $this->spatialCmp(
                    $drawingRooms[$left],
                    $drawingRooms[$right],
                ));
                usort($group, function (ProjectArea $left, ProjectArea $right): int {
                    $leftIndex = $this->trailingIndex((string) $left->displayName());
                    $rightIndex = $this->trailingIndex((string) $right->displayName());
                    if ($leftIndex !== null && $rightIndex !== null && $leftIndex !== $rightIndex) {
                        return $leftIndex <=> $rightIndex;
                    }

                    return ((int) $left->sort_order) <=> ((int) $right->sort_order)
                        ?: $left->id <=> $right->id;
                });
                $confidence = $pass === 'storey|name|meters' && count($group) === count($roomIndexes)
                    ? 0.82
                    : 0.55;
                $count = min(count($group), count($roomIndexes));
                for ($offset = 0; $offset < $count; $offset++) {
                    $hit = $this->hitFromDrawingRoom($drawingRooms[$roomIndexes[$offset]]);
                    $hit['confidence'] = $confidence;
                    $hits[$group[$offset]->id] = $hit;
                    $usedDrawing[$roomIndexes[$offset]] = true;
                }
            }
        }

        $leftoverBuckets = [];
        foreach ($areas as $area) {
            if (isset($hits[$area->id])) {
                continue;
            }
            $key = $this->candidateKey($area, 'storey|name');
            if ($key === '') {
                continue;
            }
            $leftoverBuckets[$key][] = $area;
        }
        foreach ($leftoverBuckets as $key => $group) {
            $roomIndexes = [];
            foreach ($drawingRooms as $index => $room) {
                if ($this->drawingCandidateKey($room, 'storey|name') === $key) {
                    $roomIndexes[] = $index;
                }
            }
            if ($roomIndexes === []) {
                continue;
            }
            usort($roomIndexes, fn (int $left, int $right) => $this->spatialCmp(
                $drawingRooms[$left],
                $drawingRooms[$right],
            ));
            usort($group, function (ProjectArea $left, ProjectArea $right): int {
                $leftIndex = $this->trailingIndex((string) $left->displayName());
                $rightIndex = $this->trailingIndex((string) $right->displayName());
                if ($leftIndex !== null && $rightIndex !== null && $leftIndex !== $rightIndex) {
                    return $leftIndex <=> $rightIndex;
                }

                return ((int) $left->sort_order) <=> ((int) $right->sort_order)
                    ?: $left->id <=> $right->id;
            });
            $cycle = count($roomIndexes);
            foreach ($group as $offset => $area) {
                $hit = $this->hitFromDrawingRoom($drawingRooms[$roomIndexes[$offset % $cycle]]);
                $hit['confidence'] = 0.4;
                $hits[$area->id] = $hit;
            }
        }

        return $hits;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function spatialCmp(array $left, array $right): int
    {
        return ((int) ($left['page'] ?? 1)) <=> ((int) ($right['page'] ?? 1))
            ?: round((float) ($left['x'] ?? 0), 3) <=> round((float) ($right['x'] ?? 0), 3)
            ?: round((float) ($left['y'] ?? 0), 3) <=> round((float) ($right['y'] ?? 0), 3);
    }

    private function candidateKey(ProjectArea $area, string $pass): string
    {
        $storey = $this->canonicalFloor((string) ($area->floor?->name ?? ''));
        $name = $this->matchName((string) $area->displayName());
        $meters = $area->square_meters !== null ? round(((float) $area->square_meters) / 0.05) * 0.05 : null;
        if ($storey === '' || $storey === 'onbekend') {
            return '';
        }

        return match ($pass) {
            'storey|name|meters' => $name === '' || $meters === null ? '' : $storey.'|'.$name.'|'.number_format($meters, 2, '.', ''),
            default => $name === '' ? '' : $storey.'|'.$name,
        };
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function drawingCandidateKey(array $room, string $pass): string
    {
        $storey = $this->canonicalFloor((string) ($room['floor'] ?? ''));
        $name = $this->matchName((string) ($room['room_name'] ?? ''));
        $meters = isset($room['square_meters']) ? round(((float) $room['square_meters']) / 0.05) * 0.05 : null;
        if ($storey === '' || $storey === 'onbekend') {
            return '';
        }

        return match ($pass) {
            'storey|name|meters' => $name === '' || $meters === null ? '' : $storey.'|'.$name.'|'.number_format($meters, 2, '.', ''),
            default => $name === '' ? '' : $storey.'|'.$name,
        };
    }

    private function canonicalFloor(string $floor): string
    {
        $label = new FloorLabel;
        $base = $label->base($floor);

        return mb_strtolower(trim($base !== '' ? $base : $floor));
    }

    private function matchName(string $name): string
    {
        $normalized = PdfTextNormalizer::name($name);
        $stripped = PdfTextNormalizer::stripTrailingIndex($normalized);
        if ($stripped !== $normalized) {
            return $stripped;
        }
        if (preg_match('/^(.*?)(?:\s+\d{1,3})$/u', $normalized, $match) === 1) {
            $base = trim($match[1]);
            if ($base !== '' && preg_match('/\d+[.\-]\d+/u', $base) !== 1) {
                return $base;
            }
        }

        return $normalized;
    }

    private function trailingIndex(string $name): ?int
    {
        if (preg_match('/\s+(\d{1,3})$/u', trim($name), $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @return list<array<string, mixed>>
     */
    private function collapseNearbyDrawingRooms(array $rooms): array
    {
        $kept = [];
        foreach ($rooms as $room) {
            $merged = false;
            foreach ($kept as $index => $existing) {
                if ((int) ($existing['page'] ?? 0) !== (int) ($room['page'] ?? 0)) {
                    continue;
                }
                $nameKey = $this->drawingCandidateKey($existing, 'storey|name');
                if ($nameKey === '' || $nameKey !== $this->drawingCandidateKey($room, 'storey|name')) {
                    continue;
                }
                $dx = (float) ($existing['x'] ?? 0) - (float) ($room['x'] ?? 0);
                $dy = (float) ($existing['y'] ?? 0) - (float) ($room['y'] ?? 0);
                if (hypot($dx, $dy) > 0.04) {
                    continue;
                }
                if ($this->drawingRoomRank($room) > $this->drawingRoomRank($existing)) {
                    $kept[$index] = $room;
                }
                $merged = true;
                break;
            }
            if (! $merged) {
                $kept[] = $room;
            }
        }

        return array_values($kept);
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function drawingRoomRank(array $room): int
    {
        $rank = 0;
        if (! empty($room['contour_id']) || ! empty($room['polygon'])) {
            $rank += 2;
        }
        if (isset($room['square_meters'])) {
            $rank += 1;
        }

        return $rank;
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     */
    private function alreadyReported(ProjectArea $area, array $matches): bool
    {
        foreach ($matches as $row) {
            if ((int) ($row['area_id'] ?? 0) === (int) $area->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{page:int,text:string}>  $clusters
     * @return array<int, string>
     */
    private function pageFloors(array $clusters): array
    {
        $byPage = [];
        foreach ($clusters as $cluster) {
            $byPage[(int) $cluster['page']][] = (string) $cluster['text'];
        }
        $label = new FloorLabel;
        $floors = [];
        foreach ($byPage as $page => $texts) {
            $floors[$page] = $label->canonical(implode(' ', $texts)) ?? 'Onbekend';
        }

        return $floors;
    }

    private function roomNameFromText(string $text): ?string
    {
        $text = PdfTextNormalizer::undouble($text);
        $text = preg_replace('/(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/u', ' ', $text) ?? $text;
        foreach ($this->recoveredNumbers($text) as $number) {
            $text = preg_replace('/(?<![0-9a-z])'.preg_quote($number, '/').'(?![0-9a-z])/iu', ' ', $text) ?? $text;
        }
        $text = PdfTextNormalizer::name($text);
        if ($text === '' || mb_strlen($text) < 2 || mb_strlen($text) > 40) {
            return null;
        }
        if (preg_match('/^(begane\s*grond|verdieping|legenda|schaal|pagina|tekening|installatie)\b/u', $text) === 1) {
            return null;
        }
        if (preg_match('/\p{L}/u', $text) !== 1) {
            return null;
        }

        return $text;
    }
}
