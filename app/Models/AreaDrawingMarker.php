<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_area_id', 'project_document_id', 'page', 'x', 'y', 'width', 'height', 'label_text', 'label_x', 'label_y', 'polygon', 'confidence', 'source', 'drawing_room_id',
])]
class AreaDrawingMarker extends Model
{
    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'x' => 'float',
            'y' => 'float',
            'width' => 'float',
            'height' => 'float',
            'label_x' => 'float',
            'label_y' => 'float',
            'polygon' => 'array',
            'confidence' => 'float',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(ProjectArea::class, 'project_area_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ProjectDocument::class, 'project_document_id');
    }

    /**
     * @param  list<array{x?:mixed,y?:mixed}>|null  $polygon
     * @return list<array{x:float,y:float}>|null
     */
    public static function sanitizePolygon(?array $polygon): ?array
    {
        if ($polygon === null || $polygon === []) {
            return null;
        }

        $points = [];
        foreach (array_slice($polygon, 0, 80) as $point) {
            if (! is_array($point) || ! isset($point['x'], $point['y'])) {
                continue;
            }
            $points[] = [
                'x' => max(0, min(1, round((float) $point['x'], 6))),
                'y' => max(0, min(1, round((float) $point['y'], 6))),
            ];
        }

        return count($points) >= 3 ? array_values($points) : null;
    }

    public function toBoardArray(): array
    {
        $box = $this->focusBox();

        return [
            'id' => $this->id,
            'page' => $this->page,
            'x' => (float) $this->x,
            'y' => (float) $this->y,
            'width' => $this->width !== null ? (float) $this->width : null,
            'height' => $this->height !== null ? (float) $this->height : null,
            'label_text' => $this->label_text,
            'label_x' => $this->label_x !== null ? (float) $this->label_x : null,
            'label_y' => $this->label_y !== null ? (float) $this->label_y : null,
            'polygon' => $this->polygon,
            'confidence' => (float) $this->confidence,
            'source' => $this->source,
            'drawing_room_id' => $this->drawing_room_id,
            'confidence_label' => $this->confidenceLabel(),
            'center_x' => $box !== null ? round($box['x'] + ($box['w'] / 2), 6) : null,
            'center_y' => $box !== null ? round($box['y'] + ($box['h'] / 2), 6) : null,
            'bbox' => $box,
        ];
    }

    public function hasPosition(): bool
    {
        return $this->focusBox() !== null;
    }

    public function confidenceLabel(): string
    {
        $value = (float) $this->confidence;
        if ($value >= 0.9) {
            return 'hoog';
        }
        if ($value >= 0.7) {
            return 'midden';
        }

        return 'laag';
    }

    /**
     * @return array{project_area_id: int, drawing_marker_id: int, page: int, center_x: float, center_y: float, bbox: array{x: float, y: float, w: float, h: float}}|null
     */
    public function jumpTarget(): ?array
    {
        $box = $this->focusBox();
        if ($box === null) {
            return null;
        }

        return [
            'project_area_id' => (int) $this->project_area_id,
            'drawing_marker_id' => (int) $this->id,
            'page' => (int) $this->page,
            'center_x' => round($box['x'] + ($box['w'] / 2), 6),
            'center_y' => round($box['y'] + ($box['h'] / 2), 6),
            'bbox' => $box,
        ];
    }

    /**
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    public function focusBox(): ?array
    {
        $page = (int) $this->page;
        if ($page < 1) {
            return null;
        }

        $polygon = is_array($this->polygon) ? $this->polygon : null;
        if ($polygon !== null && count($polygon) >= 3) {
            $minX = null;
            $minY = null;
            $maxX = null;
            $maxY = null;
            $points = 0;
            foreach ($polygon as $point) {
                if (! is_array($point) || ! isset($point['x'], $point['y'])) {
                    continue;
                }
                $x = (float) $point['x'];
                $y = (float) $point['y'];
                if (! is_finite($x) || ! is_finite($y)) {
                    continue;
                }
                $points++;
                $minX = $minX === null ? $x : min($minX, $x);
                $minY = $minY === null ? $y : min($minY, $y);
                $maxX = $maxX === null ? $x : max($maxX, $x);
                $maxY = $maxY === null ? $y : max($maxY, $y);
            }
            if ($points >= 3 && $maxX > $minX && $maxY > $minY) {
                return [
                    'x' => $this->clampCoord($minX),
                    'y' => $this->clampCoord($minY),
                    'w' => $this->clampCoord($maxX - $minX),
                    'h' => $this->clampCoord($maxY - $minY),
                ];
            }
        }

        if ($this->x === null || $this->y === null || ! is_numeric($this->x) || ! is_numeric($this->y)) {
            return null;
        }

        $width = $this->width !== null && is_numeric($this->width) && (float) $this->width > 0
            ? (float) $this->width
            : 0.06;
        $height = $this->height !== null && is_numeric($this->height) && (float) $this->height > 0
            ? (float) $this->height
            : 0.03;
        if ($width <= 0.001 || $height <= 0.001) {
            return null;
        }

        return [
            'x' => $this->clampCoord((float) $this->x),
            'y' => $this->clampCoord((float) $this->y),
            'w' => $this->clampCoord($width),
            'h' => $this->clampCoord($height),
        ];
    }

    private function clampCoord(float $value): float
    {
        return max(0, min(1, round($value, 6)));
    }
}
