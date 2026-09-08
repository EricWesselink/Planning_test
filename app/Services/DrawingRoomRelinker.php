<?php

namespace App\Services;

use App\Models\Project;
use App\Services\Meetstaat\DrawingColorMatcher;
use App\Services\Meetstaat\PdfPageGeometry;
use Illuminate\Support\Facades\Storage;

class DrawingRoomRelinker
{
    public function __construct(
        private PdfPageGeometry $geometry,
        private DrawingColorMatcher $colors,
        private RoomMarkerMatcher $matcher,
    ) {}

    /**
     * @return array{
     *     project_id: int,
     *     project: string,
     *     saved: int,
     *     skipped: int,
     *     unmatched: list<string>,
     *     review: list<string>,
     *     none: list<string>,
     *     matches: list<array<string, mixed>>,
     *     counts: array{auto: int, manual: int, none: int, review: int, unmatched: int},
     *     error: ?string
     * }
     */
    public function relink(Project $project, bool $missingOnly = true): array
    {
        $project->loadMissing(['documents', 'areas.floor', 'areas.markers']);
        $empty = $this->emptyReport($project);
        $document = $project->plattegrond();
        if ($document === null) {
            $empty['error'] = 'Geen plattegrond opgeslagen.';

            return $empty;
        }
        if (! $document->isPdf()) {
            $empty['error'] = 'Plattegrond is geen PDF.';

            return $empty;
        }

        $relative = (string) $document->file_path;
        if ($relative === '' || ! Storage::disk('local')->exists($relative)) {
            $empty['error'] = 'Opgeslagen PDF ontbreekt.';

            return $empty;
        }

        $path = Storage::disk('local')->path($relative);
        $extracted = $this->geometry->extract($path);
        $pages = $extracted['pages'] ?? [];
        if ($pages === []) {
            $empty['error'] = 'Geen tekst of geometrie in de PDF.';

            return $empty;
        }

        $found = $this->colors->roomsFromPages($pages);
        $drawingRooms = [];
        foreach ($found['rooms'] ?? [] as $room) {
            $page = $this->pageByNumber($pages, (int) ($room['page'] ?? 1));
            $drawingRooms[] = $this->matcher->normalizeDrawingRoom(
                $this->toBoardRoom($room, $page)
            );
        }

        $result = $this->matcher->match(
            $project,
            $document,
            $this->textsToItems($pages),
            [],
            [
                'missing_only' => $missingOnly,
                'drawing_rooms' => $drawingRooms,
            ],
        );

        return [
            'project_id' => $project->id,
            'project' => $project->name,
            'saved' => $result['saved'],
            'skipped' => $result['skipped'],
            'unmatched' => $result['unmatched'],
            'review' => $result['review'],
            'none' => $result['none'] ?? $result['unmatched'],
            'unmatched_ids' => $result['unmatched_ids'],
            'review_ids' => $result['review_ids'],
            'matches' => $result['matches'],
            'counts' => $result['counts'],
            'error' => null,
        ];
    }

    /**
     * @return array{
     *     project_id: int,
     *     project: string,
     *     saved: int,
     *     skipped: int,
     *     unmatched: list<string>,
     *     review: list<string>,
     *     none: list<string>,
     *     matches: list<array<string, mixed>>,
     *     counts: array{auto: int, manual: int, none: int, review: int, unmatched: int},
     *     error: ?string
     * }
     */
    private function emptyReport(Project $project): array
    {
        $unmatched = $project->areas
            ->map(fn ($area) => trim(($area->displayNumber() ?? '').' '.$area->displayName()))
            ->filter()
            ->values()
            ->all();

        return [
            'project_id' => $project->id,
            'project' => $project->name,
            'saved' => 0,
            'skipped' => 0,
            'unmatched' => $unmatched,
            'review' => [],
            'none' => $unmatched,
            'unmatched_ids' => $project->areas->pluck('id')->all(),
            'review_ids' => [],
            'matches' => [],
            'counts' => [
                'auto' => 0,
                'manual' => 0,
                'none' => count($unmatched),
                'review' => 0,
                'unmatched' => count($unmatched),
            ],
            'error' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    private function pageByNumber(array $pages, int $number): array
    {
        foreach ($pages as $page) {
            if ((int) ($page['page'] ?? 0) === $number) {
                return $page;
            }
        }

        return $pages[0] ?? ['width' => 595.0, 'height' => 842.0, 'page' => $number];
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function toBoardRoom(array $room, array $page): array
    {
        $width = max(1.0, (float) ($page['width'] ?? 595));
        $height = max(1.0, (float) ($page['height'] ?? 842));
        $x = (float) ($room['x'] ?? $room['meter_x'] ?? 0);
        $y = (float) ($room['y'] ?? $room['meter_y'] ?? 0);
        $nx = max(0.0, min(1.0, $x / $width));
        $ny = max(0.0, min(1.0, 1 - ($y / $height)));
        $label = trim((string) ($room['room_number'] ?? '').' '.(string) ($room['room_name'] ?? ''));
        if (($room['square_meters'] ?? null) !== null) {
            $label = trim($label.' '.$room['square_meters'].' m²');
        }

        $cellX = (float) ($room['cell_x'] ?? 0);
        $cellY = (float) ($room['cell_y'] ?? 0);
        $cellW = (float) ($room['cell_width'] ?? 0);
        $cellH = (float) ($room['cell_height'] ?? 0);
        $polygon = null;
        $boxWidth = 0.08;
        $boxHeight = 0.02;
        if ($cellW > 1 && $cellH > 1) {
            $x0 = max(0.0, min(1.0, $cellX / $width));
            $x1 = max(0.0, min(1.0, ($cellX + $cellW) / $width));
            $y0 = max(0.0, min(1.0, 1 - (($cellY + $cellH) / $height)));
            $y1 = max(0.0, min(1.0, 1 - ($cellY / $height)));
            $polygon = [
                ['x' => $x0, 'y' => $y0],
                ['x' => $x1, 'y' => $y0],
                ['x' => $x1, 'y' => $y1],
                ['x' => $x0, 'y' => $y1],
            ];
            $boxWidth = max(0.02, $x1 - $x0);
            $boxHeight = max(0.012, $y1 - $y0);
        }

        return [
            'drawing_room_id' => (string) ($room['contour_id'] ?? $room['key'] ?? ''),
            'contour_id' => $room['contour_id'] ?? null,
            'page' => (int) ($room['page'] ?? 1),
            'floor' => (string) ($room['floor'] ?? 'Onbekend'),
            'room_number' => $room['room_number'] ?? null,
            'room_name' => (string) ($room['room_name'] ?? ''),
            'square_meters' => $room['square_meters'] ?? null,
            'x' => $nx,
            'y' => $ny,
            'width' => $boxWidth,
            'height' => $boxHeight,
            'label_text' => $label,
            'source' => 'auto',
            'polygon' => $polygon,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<array{page:int,text:string,x:float,y:float,w:float,h:float,source:string}>
     */
    private function textsToItems(array $pages): array
    {
        $items = [];
        foreach ($pages as $page) {
            $width = max(1.0, (float) ($page['width'] ?? 595));
            $height = max(1.0, (float) ($page['height'] ?? 842));
            $pageNumber = max(1, (int) ($page['page'] ?? 1));
            foreach ($page['texts'] ?? [] as $text) {
                $value = trim((string) ($text['text'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $x = (float) ($text['x'] ?? 0);
                $y = (float) ($text['y'] ?? 0);
                $items[] = [
                    'page' => $pageNumber,
                    'text' => mb_substr($value, 0, 160),
                    'x' => max(0.0, min(1.0, $x / $width)),
                    'y' => max(0.0, min(1.0, 1 - ($y / $height))),
                    'w' => max(0.006, min(0.03, mb_strlen($value) * 0.0035)),
                    'h' => 0.012,
                    'source' => 'text',
                ];
            }
        }

        return $items;
    }
}
