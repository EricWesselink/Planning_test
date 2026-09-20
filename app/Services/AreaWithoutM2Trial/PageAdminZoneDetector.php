<?php

namespace App\Services\AreaWithoutM2Trial;

/**
 * Administrative page regions (legend, title block, revision table) must not yield millimetre measures.
 */
class PageAdminZoneDetector
{
    private const KEYWORD_PATTERN = '/\b(legenda|renvooi|revisie|titelblok|disclaimer|materiaal|artikel|kleur(?:nr|nummer)?|getekend|formaat|project(?:nr|nummer)?|schaal|datum)\b/iu';

    /**
     * @param  array<string, mixed>  $page
     * @return list<array{left: float, right: float, bottom: float, top: float, kind: string, reason: string}>
     */
    public function detect(array $page): array
    {
        $texts = is_array($page['texts'] ?? null) ? $page['texts'] : [];
        $width = (float) ($page['width'] ?? 0);
        $height = (float) ($page['height'] ?? 0);
        $pageMin = min(max($width, 1.0), max($height, 1.0));
        $radius = max(56.0, $pageMin * 0.05);
        $pad = max(24.0, $pageMin * 0.02);

        $seeds = [];
        foreach ($texts as $item) {
            if ($this->isSeed((string) ($item['text'] ?? ''))) {
                $seeds[] = $item;
            }
        }

        $zones = [];
        foreach ($seeds as $seed) {
            $members = $this->clusterFrom($seed, $texts, $radius);
            $kind = $this->kindOf((string) ($seed['text'] ?? ''));
            $zones[] = $this->boxOf($members, $pad, $kind);
        }

        return $this->mergeOverlapping($zones);
    }

    /**
     * @param  list<array{left: float, right: float, bottom: float, top: float, kind?: string, reason?: string}>  $zones
     */
    public function reasonAt(float $x, float $y, array $zones): ?string
    {
        foreach ($zones as $zone) {
            if ($x < (float) $zone['left'] || $x > (float) $zone['right']) {
                continue;
            }
            if ($y < (float) $zone['bottom'] || $y > (float) $zone['top']) {
                continue;
            }

            return (string) ($zone['reason'] ?? 'legenda/titelblok/revisie');
        }

        return null;
    }

    private function isSeed(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (preg_match(self::KEYWORD_PATTERN, $text) === 1) {
            return true;
        }
        if (preg_match('/^(?:v|pl|w|p)\d{2}\b/iu', $text) === 1) {
            return true;
        }
        if (str_contains($text, '=') && preg_match('/[A-Za-zÀ-ÿ]/u', $text) === 1) {
            return true;
        }
        if (preg_match('/\d{1,2}[-.\/]\d{1,2}[-.\/]\d{2,4}/', $text) === 1) {
            return true;
        }
        if ($this->isProductLike($text)) {
            return true;
        }

        return false;
    }

    private function isProductLike(string $text): bool
    {
        if (preg_match('/\b[A-Z]-\d{2}-\d{2}\b/', $text) === 1) {
            return false;
        }
        if (preg_match('/\d+(?:[.,]\d+)?\s*m(?:²|2)\b/u', $text) === 1) {
            return false;
        }
        if (preg_match('/^\d{3,5}(?:\s*mm)?$/iu', $text) === 1) {
            return false;
        }

        return preg_match('/[A-Za-zÀ-ÿ].*\d{3,5}|\d{3,5}.*[A-Za-zÀ-ÿ]/u', $text) === 1;
    }

    /**
     * @param  array{text?: string, x?: float, y?: float}  $seed
     * @param  list<array{text?: string, x?: float, y?: float}>  $texts
     * @return list<array{text?: string, x?: float, y?: float}>
     */
    private function clusterFrom(array $seed, array $texts, float $radius): array
    {
        $members = [$seed];
        $used = [$this->itemKey($seed) => true];
        $changed = true;
        $guard = 0;
        while ($changed && $guard < 8) {
            $changed = false;
            $guard++;
            foreach ($texts as $item) {
                $key = $this->itemKey($item);
                if (isset($used[$key]) || ! $this->canBridge($item)) {
                    continue;
                }
                foreach ($members as $member) {
                    if ($this->distance($item, $member) <= $radius) {
                        $members[] = $item;
                        $used[$key] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        }

        foreach ($texts as $item) {
            $key = $this->itemKey($item);
            if (isset($used[$key])) {
                continue;
            }
            foreach ($members as $member) {
                if ($this->distance($item, $member) <= $radius) {
                    $members[] = $item;
                    $used[$key] = true;
                    break;
                }
            }
        }

        return $members;
    }

    /**
     * @param  array{text?: string}  $item
     */
    private function canBridge(array $item): bool
    {
        return preg_match('/^\d{3,5}(?:\s*mm)?$/iu', trim((string) ($item['text'] ?? ''))) !== 1;
    }

    /**
     * @param  list<array{x?: float, y?: float}>  $members
     * @return array{left: float, right: float, bottom: float, top: float, kind: string, reason: string}
     */
    private function boxOf(array $members, float $pad, string $kind): array
    {
        $xs = array_map(fn (array $item): float => (float) ($item['x'] ?? 0), $members);
        $ys = array_map(fn (array $item): float => (float) ($item['y'] ?? 0), $members);

        return [
            'left' => min($xs) - $pad,
            'right' => max($xs) + $pad,
            'bottom' => min($ys) - $pad,
            'top' => max($ys) + $pad,
            'kind' => $kind,
            'reason' => $kind,
        ];
    }

    private function kindOf(string $text): string
    {
        if (preg_match('/\b(legenda|renvooi|materiaal|artikel|kleur)/iu', $text) === 1 || $this->isProductLike($text) || preg_match('/^(?:v|pl|w|p)\d{2}\b/iu', $text) === 1) {
            return 'legenda/productcode';
        }
        if (preg_match('/\b(revisie|datum)\b/iu', $text) === 1 || preg_match('/\d{1,2}[-.\/]\d{1,2}[-.\/]\d{2,4}/', $text) === 1) {
            return 'revisie/titelblok';
        }

        return 'titelblok/administratie';
    }

    /**
     * @param  list<array{left: float, right: float, bottom: float, top: float, kind: string, reason: string}>  $zones
     * @return list<array{left: float, right: float, bottom: float, top: float, kind: string, reason: string}>
     */
    private function mergeOverlapping(array $zones): array
    {
        $merged = [];
        foreach ($zones as $zone) {
            $hit = null;
            foreach ($merged as $index => $existing) {
                if ($this->overlaps($existing, $zone)) {
                    $hit = $index;
                    break;
                }
            }
            if ($hit === null) {
                $merged[] = $zone;

                continue;
            }
            $merged[$hit] = [
                'left' => min($merged[$hit]['left'], $zone['left']),
                'right' => max($merged[$hit]['right'], $zone['right']),
                'bottom' => min($merged[$hit]['bottom'], $zone['bottom']),
                'top' => max($merged[$hit]['top'], $zone['top']),
                'kind' => $merged[$hit]['kind'],
                'reason' => $merged[$hit]['reason'],
            ];
        }

        return $merged;
    }

    /**
     * @param  array{left: float, right: float, bottom: float, top: float}  $a
     * @param  array{left: float, right: float, bottom: float, top: float}  $b
     */
    private function overlaps(array $a, array $b): bool
    {
        return $a['left'] <= $b['right'] && $b['left'] <= $a['right']
            && $a['bottom'] <= $b['top'] && $b['bottom'] <= $a['top'];
    }

    /**
     * @param  array{x?: float, y?: float}  $a
     * @param  array{x?: float, y?: float}  $b
     */
    private function distance(array $a, array $b): float
    {
        return hypot((float) ($a['x'] ?? 0) - (float) ($b['x'] ?? 0), (float) ($a['y'] ?? 0) - (float) ($b['y'] ?? 0));
    }

    /**
     * @param  array{text?: string, x?: float, y?: float, page?: int}  $item
     */
    private function itemKey(array $item): string
    {
        return round((float) ($item['x'] ?? 0), 1).'|'.round((float) ($item['y'] ?? 0), 1).'|'.($item['text'] ?? '');
    }
}
