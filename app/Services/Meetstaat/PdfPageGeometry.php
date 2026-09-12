<?php

namespace App\Services\Meetstaat;

use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Element\ElementXRef;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;
use Smalot\PdfParser\XObject\Form;

class PdfPageGeometry
{
    public function __construct(private PdfMemoryGuard $memory = new PdfMemoryGuard) {}

    /**
     * @return array{
     *     pages: list<array{
     *         page: int,
     *         width: float,
     *         height: float,
     *         texts: list<array{text: string, x: float, y: float, page: int}>,
     *         fills: list<array{x: float, y: float, width: float, height: float, color: RgbColor, area: float, page: int}>,
     *         walls: list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>
     *     }>
     * }
     */
    public function extract(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) < 1) {
            return ['pages' => []];
        }

        $this->memory->ensureCanParse($path);

        $document = null;
        try {
            $document = (new Parser)->parseFile($path);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable) {
            return ['pages' => []];
        }

        try {
            $pages = [];
            foreach (array_values($document->getPages()) as $index => $page) {
                $pages[] = $this->extractPage($page, $index + 1);
            }

            $bbox = $this->textsFromBBox($path);
            foreach ($pages as &$page) {
                $words = $bbox['texts'][$page['page']] ?? [];
                if ($this->preferBBox($page['texts'], $words)) {
                    $page['texts'] = $words;
                }
                $size = $bbox['sizes'][$page['page']] ?? null;
                if (is_array($size)) {
                    $page['width'] = max($page['width'], (float) $size['width']);
                    $page['height'] = max($page['height'], (float) $size['height']);
                }
            }
            unset($page);

            return ['pages' => $pages];
        } finally {
            unset($document);
            $this->memory->release();
        }
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int}>  $tm
     * @param  list<array{text: string, x: float, y: float, page: int}>  $bbox
     */
    private function preferBBox(array $tm, array $bbox): bool
    {
        if ($bbox === []) {
            return false;
        }
        $readableTm = $this->readableCount($tm);
        $readableBbox = $this->readableCount($bbox);
        if ($readableTm >= 8 && $readableTm >= ($readableBbox * 0.5)) {
            return false;
        }

        return $readableBbox > $readableTm;
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     */
    private function readableCount(array $items): int
    {
        return count(array_filter(
            $items,
            fn (array $item) => (bool) preg_match('/[a-zA-Zà-ÿ]{3,}/u', (string) $item['text'])
        ));
    }

    /**
     * @return array{page: int, width: float, height: float, texts: list<array{text: string, x: float, y: float, page: int}>, fills: list<array{x: float, y: float, width: float, height: float, color: RgbColor, area: float, page: int}>, walls: list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>}
     */
    private function extractPage(Page $page, int $number): array
    {
        $box = $this->mediaBox($page);
        $width = max(1.0, (float) ($box[2] ?? 595) - (float) ($box[0] ?? 0));
        $height = max(1.0, (float) ($box[3] ?? 842) - (float) ($box[1] ?? 0));
        $vectors = $this->vectors($page, $number, $width, $height);

        return [
            'page' => $number,
            'width' => $width,
            'height' => $height,
            'texts' => $this->texts($page, $number),
            'fills' => $vectors['fills'],
            'walls' => $vectors['walls'],
        ];
    }

    /**
     * @return array{0?: float, 1?: float, 2?: float, 3?: float}
     */
    private function mediaBox(Page $page): array
    {
        try {
            $details = $page->getHeader()?->getDetails(false) ?? [];
            if (isset($details['MediaBox']) && is_array($details['MediaBox'])) {
                return $details['MediaBox'];
            }
        } catch (\Throwable) {
        }

        return [0, 0, 595, 842];
    }

    /**
     * @return list<array{text: string, x: float, y: float, page: int}>
     */
    private function texts(Page $page, int $number): array
    {
        $items = [];
        try {
            $rows = $page->getDataTm();
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as $row) {
            $matrix = $row[0] ?? null;
            $text = trim((string) ($row[1] ?? ''));
            if ($text === '' || ! is_array($matrix) || count($matrix) < 6) {
                continue;
            }
            $items[] = [
                'text' => $text,
                'x' => (float) $matrix[4],
                'y' => (float) $matrix[5],
                'page' => $number,
            ];
        }

        return $items;
    }

    /**
     * @return array{texts: array<int, list<array{text: string, x: float, y: float, page: int}>>, sizes: array<int, array{width: float, height: float}>}
     */
    private function textsFromBBox(string $path): array
    {
        $binary = $this->pdftotextBinary();
        if ($binary === null) {
            return ['texts' => [], 'sizes' => []];
        }

        $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-bbox-'.bin2hex(random_bytes(8)).'.html';
        $stderr = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
        $command = escapeshellarg($binary).' -bbox '.escapeshellarg($path).' '.escapeshellarg($output).' '.$stderr;
        exec($command, $_, $code);
        if ($code !== 0 || ! is_file($output)) {
            return ['texts' => [], 'sizes' => []];
        }

        $html = (string) file_get_contents($output);
        @unlink($output);

        return $this->parseBBoxHtml($html);
    }

    /**
     * @return array{texts: array<int, list<array{text: string, x: float, y: float, page: int}>>, sizes: array<int, array{width: float, height: float}>}
     */
    private function parseBBoxHtml(string $html): array
    {
        $pages = [];
        $sizes = [];
        if (! preg_match_all('/<page\b([^>]*)>(.*?)<\/page>/is', $html, $pageMatches, PREG_SET_ORDER)) {
            return ['texts' => [], 'sizes' => []];
        }

        foreach ($pageMatches as $index => $pageMatch) {
            $number = $index + 1;
            $width = 595.0;
            $height = 842.0;
            if (preg_match('/\bwidth="([\d.]+)"/', $pageMatch[1], $widthMatch)) {
                $width = (float) $widthMatch[1];
            }
            if (preg_match('/\bheight="([\d.]+)"/', $pageMatch[1], $heightMatch)) {
                $height = (float) $heightMatch[1];
            }
            $sizes[$number] = ['width' => $width, 'height' => $height];
            if (! preg_match_all('/<word xMin="([^"]+)" yMin="([^"]+)" xMax="([^"]+)" yMax="([^"]+)">([^<]*)<\/word>/', $pageMatch[2], $words, PREG_SET_ORDER)) {
                $pages[$number] = [];

                continue;
            }
            $items = [];
            foreach ($words as $word) {
                $text = trim(html_entity_decode($word[5], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '' || $text === 'edit_square') {
                    continue;
                }
                $xMin = (float) $word[1];
                $yMin = (float) $word[2];
                $xMax = (float) $word[3];
                $yMax = (float) $word[4];
                $items[] = [
                    'text' => $text,
                    'x' => ($xMin + $xMax) / 2,
                    'y' => $height - (($yMin + $yMax) / 2),
                    'page' => $number,
                ];
            }
            $pages[$number] = $items;
        }

        return ['texts' => $pages, 'sizes' => $sizes];
    }

    private function pdftotextBinary(): ?string
    {
        $which = trim((string) shell_exec('where pdftotext 2>NUL'));
        if ($which === '') {
            return null;
        }

        return explode("\n", str_replace("\r", '', $which))[0];
    }

    /**
     * @return array{
     *     fills: list<array{x: float, y: float, width: float, height: float, color: RgbColor, area: float, page: int}>,
     *     walls: list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>
     * }
     */
    private function vectors(Page $page, int $number, float $pageWidth, float $pageHeight): array
    {
        $content = $this->pageContent($page);
        if ($content === '') {
            return ['fills' => [], 'walls' => []];
        }

        $fills = [];
        $walls = [];
        $fill = new RgbColor(0, 0, 0);
        $inText = false;
        $rect = null;
        $path = null;
        $numbers = [];
        $ctm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $stack = [];
        $pageArea = $pageWidth * $pageHeight;

        foreach ($this->tokenize($content) as $token) {
            if ($token === 'BT') {
                $inText = true;
                $numbers = [];

                continue;
            }
            if ($token === 'ET') {
                $inText = false;
                $numbers = [];

                continue;
            }
            if ($inText) {
                $numbers = [];

                continue;
            }
            if (is_numeric($token)) {
                $numbers[] = (float) $token;

                continue;
            }

            if ($token === 'q') {
                $stack[] = $ctm;
            } elseif ($token === 'Q') {
                $ctm = array_pop($stack) ?: [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
                $rect = null;
                $path = null;
            } elseif ($token === 'cm' && count($numbers) >= 6) {
                $slice = array_slice($numbers, -6);
                $ctm = $this->multiplyCtm($ctm, $slice);
            } elseif ($token === 'rg' && count($numbers) >= 3) {
                $fill = RgbColor::fromRgb($numbers[count($numbers) - 3], $numbers[count($numbers) - 2], $numbers[count($numbers) - 1]);
            } elseif ($token === 'g' && $numbers !== []) {
                $fill = RgbColor::fromGray($numbers[array_key_last($numbers)]);
            } elseif ($token === 'k' && count($numbers) >= 4) {
                $slice = array_slice($numbers, -4);
                $fill = RgbColor::fromCmyk($slice[0], $slice[1], $slice[2], $slice[3]);
            } elseif ($token === 're' && count($numbers) >= 4) {
                $slice = array_slice($numbers, -4);
                $rect = $this->transformedRect($ctm, $slice[0], $slice[1], $slice[2], $slice[3]);
                $path = null;
            } elseif ($token === 'm' && count($numbers) >= 2) {
                $point = $this->applyCtm($ctm, $numbers[count($numbers) - 2], $numbers[count($numbers) - 1]);
                $path = ['points' => [$point]];
                $rect = null;
            } elseif (in_array($token, ['l', 'c', 'v', 'y'], true) && is_array($path) && count($numbers) >= 2) {
                $path['points'][] = $this->applyCtm($ctm, $numbers[count($numbers) - 2], $numbers[count($numbers) - 1]);
            } elseif ($token === 'h' && is_array($path) && ($path['points'] ?? []) !== []) {
                $path['points'][] = $path['points'][0];
            } elseif (in_array($token, ['W', 'W*', 'n'], true)) {
                $rect = null;
                $path = null;
            } elseif (in_array($token, ['S', 's', 'B', 'B*', 'b', 'b*'], true)) {
                $this->appendWalls($walls, $rect, $path);
                if (in_array($token, ['B', 'B*', 'b', 'b*'], true)) {
                    $this->appendFill($fills, $rect, $path, $fill, $number, $pageArea);
                }
                $rect = null;
                $path = null;
            } elseif (in_array($token, ['f', 'F', 'f*'], true)) {
                $this->appendFill($fills, $rect, $path, $fill, $number, $pageArea);
                $rect = null;
                $path = null;
            }

            $numbers = [];
        }

        return ['fills' => $fills, 'walls' => $this->mergeAxisWalls($walls)];
    }

    /**
     * @param  list<array{x: float, y: float, width: float, height: float, color: RgbColor, area: float, page: int}>  $fills
     * @param  array{x: float, y: float, width: float, height: float}|null  $rect
     * @param  array{points?: list<array{0: float, 1: float}>}|null  $path
     */
    private function appendFill(array &$fills, ?array $rect, ?array $path, RgbColor $fill, int $number, float $pageArea): void
    {
        $shape = $rect;
        if ($shape === null && is_array($path) && ($path['points'] ?? []) !== []) {
            $xs = array_column($path['points'], 0);
            $ys = array_column($path['points'], 1);
            $minX = min($xs);
            $minY = min($ys);
            $shape = [
                'x' => $minX,
                'y' => $minY,
                'width' => max($xs) - $minX,
                'height' => max($ys) - $minY,
            ];
        }
        if (! is_array($shape) || $fill->isIgnored()) {
            return;
        }
        $area = $shape['width'] * $shape['height'];
        if ($area < 20 || $area >= $pageArea * 0.92) {
            return;
        }
        $fills[] = [
            'x' => $shape['x'],
            'y' => $shape['y'],
            'width' => $shape['width'],
            'height' => $shape['height'],
            'color' => $fill,
            'area' => $area,
            'page' => $number,
        ];
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @param  array{x: float, y: float, width: float, height: float}|null  $rect
     * @param  array{points?: list<array{0: float, 1: float}>}|null  $path
     */
    private function appendWalls(array &$walls, ?array $rect, ?array $path): void
    {
        $segments = [];
        if (is_array($rect)) {
            $x0 = $rect['x'];
            $y0 = $rect['y'];
            $x1 = $rect['x'] + $rect['width'];
            $y1 = $rect['y'] + $rect['height'];
            $segments = [
                [$x0, $y0, $x1, $y0],
                [$x1, $y0, $x1, $y1],
                [$x1, $y1, $x0, $y1],
                [$x0, $y1, $x0, $y0],
            ];
        } elseif (is_array($path)) {
            $points = $path['points'] ?? [];
            for ($i = 1; $i < count($points); $i++) {
                $segments[] = [$points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]];
            }
        }

        foreach ($segments as $segment) {
            $wall = $this->axisWall($segment[0], $segment[1], $segment[2], $segment[3]);
            if ($wall !== null) {
                $walls[] = $wall;
            }
        }
    }

    /**
     * @return array{x1: float, y1: float, x2: float, y2: float, axis: string}|null
     */
    private function axisWall(float $x1, float $y1, float $x2, float $y2): ?array
    {
        $dx = abs($x2 - $x1);
        $dy = abs($y2 - $y1);
        if ($dx < 1.5 && $dy >= 8) {
            return ['x1' => $x1, 'y1' => min($y1, $y2), 'x2' => $x1, 'y2' => max($y1, $y2), 'axis' => 'v'];
        }
        if ($dy < 1.5 && $dx >= 8) {
            return ['x1' => min($x1, $x2), 'y1' => $y1, 'x2' => max($x1, $x2), 'y2' => $y1, 'axis' => 'h'];
        }

        return null;
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @return list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>
     */
    private function mergeAxisWalls(array $walls): array
    {
        $vertical = [];
        $horizontal = [];
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') === 'v') {
                $vertical[(string) round($wall['x1'])][] = $wall;
            } elseif (($wall['axis'] ?? '') === 'h') {
                $horizontal[(string) round($wall['y1'])][] = $wall;
            }
        }

        $merged = [];
        foreach ($vertical as $group) {
            usort($group, fn (array $left, array $right) => $left['y1'] <=> $right['y1']);
            $current = null;
            foreach ($group as $wall) {
                if ($current === null) {
                    $current = $wall;

                    continue;
                }
                if ($wall['y1'] <= $current['y2'] + 10) {
                    $current['y2'] = max($current['y2'], $wall['y2']);

                    continue;
                }
                $merged[] = $current;
                $current = $wall;
            }
            if ($current !== null) {
                $merged[] = $current;
            }
        }
        foreach ($horizontal as $group) {
            usort($group, fn (array $left, array $right) => $left['x1'] <=> $right['x1']);
            $current = null;
            foreach ($group as $wall) {
                if ($current === null) {
                    $current = $wall;

                    continue;
                }
                if ($wall['x1'] <= $current['x2'] + 10) {
                    $current['x2'] = max($current['x2'], $wall['x2']);

                    continue;
                }
                $merged[] = $current;
                $current = $wall;
            }
            if ($current !== null) {
                $merged[] = $current;
            }
        }

        return $merged;
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     * @return list<float>
     */
    private function multiplyCtm(array $left, array $right): array
    {
        return [
            $left[0] * $right[0] + $left[2] * $right[1],
            $left[1] * $right[0] + $left[3] * $right[1],
            $left[0] * $right[2] + $left[2] * $right[3],
            $left[1] * $right[2] + $left[3] * $right[3],
            $left[0] * $right[4] + $left[2] * $right[5] + $left[4],
            $left[1] * $right[4] + $left[3] * $right[5] + $left[5],
        ];
    }

    /**
     * @param  list<float>  $ctm
     * @return array{0: float, 1: float}
     */
    private function applyCtm(array $ctm, float $x, float $y): array
    {
        return [
            $ctm[0] * $x + $ctm[2] * $y + $ctm[4],
            $ctm[1] * $x + $ctm[3] * $y + $ctm[5],
        ];
    }

    /**
     * @param  list<float>  $ctm
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function transformedRect(array $ctm, float $x, float $y, float $width, float $height): array
    {
        $corners = [
            $this->applyCtm($ctm, $x, $y),
            $this->applyCtm($ctm, $x + $width, $y),
            $this->applyCtm($ctm, $x, $y + $height),
            $this->applyCtm($ctm, $x + $width, $y + $height),
        ];
        $xs = array_column($corners, 0);
        $ys = array_column($corners, 1);
        $minX = min($xs);
        $minY = min($ys);

        return [
            'x' => $minX,
            'y' => $minY,
            'width' => max($xs) - $minX,
            'height' => max($ys) - $minY,
        ];
    }

    private function pageContent(Page $page): string
    {
        $parts = $this->contentsFrom($page->get('Contents'));

        $direct = $page->getContent();
        if (is_string($direct) && $direct !== '') {
            $parts[] = $direct;
        }

        try {
            foreach ($page->getXObjects() as $object) {
                if ($object instanceof Form || $object instanceof PDFObject) {
                    $stream = $object->getContent();
                    if (is_string($stream) && $stream !== '') {
                        $parts[] = $stream;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return implode("\n", array_filter($parts, fn (string $part) => $part !== ''));
    }

    /**
     * @return list<string>
     */
    private function contentsFrom(mixed $contents): array
    {
        if ($contents instanceof ElementArray) {
            $parts = [];
            foreach ($contents->getContent() as $item) {
                $parts = array_merge($parts, $this->contentsFrom($item));
            }

            return $parts;
        }

        if ($contents instanceof ElementXRef) {
            return $this->contentsFrom($contents->getObject());
        }

        if ($contents instanceof PDFObject) {
            $parts = [];
            $stream = $contents->getContent();
            if (is_string($stream) && $stream !== '') {
                $parts[] = $stream;
            }
            $elements = $contents->getHeader()?->getElements() ?? [];
            if ($elements !== [] && is_numeric(key($elements))) {
                foreach ($elements as $element) {
                    $parts = array_merge($parts, $this->contentsFrom($element));
                }
            }

            return $parts;
        }

        if (is_string($contents) && $contents !== '') {
            return [$contents];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $content): array
    {
        $tokens = [];
        $length = strlen($content);
        $index = 0;
        while ($index < $length) {
            $char = $content[$index];
            if (ctype_space($char)) {
                $index++;

                continue;
            }
            if ($char === '%') {
                $newline = strpos($content, "\n", $index);
                $index = $newline === false ? $length : $newline + 1;

                continue;
            }
            if ($char === '(') {
                $index = $this->skipString($content, $index);

                continue;
            }
            if ($char === '[') {
                $end = strpos($content, ']', $index);
                $index = $end === false ? $length : $end + 1;

                continue;
            }
            $next = $index;
            while ($next < $length && ! ctype_space($content[$next]) && ! in_array($content[$next], ['(', '[', '%'], true)) {
                $next++;
            }
            $tokens[] = substr($content, $index, $next - $index);
            $index = $next;
        }

        return $tokens;
    }

    private function skipString(string $content, int $index): int
    {
        $length = strlen($content);
        $index++;
        while ($index < $length) {
            if ($content[$index] === '\\') {
                $index += 2;

                continue;
            }
            if ($content[$index] === ')') {
                return $index + 1;
            }
            $index++;
        }

        return $length;
    }
}
