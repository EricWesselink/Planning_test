<?php

namespace App\Services\QuoteCalculation;

use App\Services\Meetstaat\PdfMemoryGuard;
use App\Support\PdftotextBinary;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;

class DrawingTextPositions
{
    public function __construct(private PdfMemoryGuard $memory = new PdfMemoryGuard) {}

    /**
     * @return list<array{page: int, width: float, height: float, texts: list<array{text: string, x: float, y: float, page: int}>}>
     */
    public function extract(string $path): array
    {
        $bbox = $this->viaPdftotextBbox($path);
        if ($bbox !== []) {
            return $bbox;
        }

        return $this->viaSmalot($path);
    }

    public function layoutText(string $path): string
    {
        $binary = PdftotextBinary::path();
        if ($binary === null || ! is_file($path)) {
            return '';
        }

        $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-layout-'.bin2hex(random_bytes(8)).'.txt';
        $command = escapeshellarg($binary).' -layout '.escapeshellarg($path).' '.escapeshellarg($output).' '.PdftotextBinary::stderrRedirect();
        exec($command, $_, $code);
        if ($code !== 0 || ! is_file($output)) {
            return '';
        }

        $text = (string) file_get_contents($output);
        @unlink($output);

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * @return list<array{page: int, width: float, height: float, texts: list<array{text: string, x: float, y: float, page: int}>}>
     */
    private function viaPdftotextBbox(string $path): array
    {
        $binary = PdftotextBinary::path();
        if ($binary === null || ! is_file($path)) {
            return [];
        }

        $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-bbox-'.bin2hex(random_bytes(8)).'.html';
        $command = escapeshellarg($binary).' -bbox '.escapeshellarg($path).' '.escapeshellarg($output).' '.PdftotextBinary::stderrRedirect();
        exec($command, $_, $code);
        if ($code !== 0 || ! is_file($output)) {
            return [];
        }

        $html = (string) file_get_contents($output);
        @unlink($output);

        return $this->parseBBoxHtml($html);
    }

    /**
     * @return list<array{page: int, width: float, height: float, texts: list<array{text: string, x: float, y: float, page: int}>}>
     */
    private function parseBBoxHtml(string $html): array
    {
        if (! preg_match_all('/<page\b([^>]*)>(.*?)<\/page>/is', $html, $pageMatches, PREG_SET_ORDER)) {
            return [];
        }

        $pages = [];
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
            if (! preg_match_all('/<word xMin="([^"]+)" yMin="([^"]+)" xMax="([^"]+)" yMax="([^"]+)">([^<]*)<\/word>/', $pageMatch[2], $words, PREG_SET_ORDER)) {
                $pages[] = ['page' => $number, 'width' => $width, 'height' => $height, 'texts' => []];

                continue;
            }

            $items = [];
            foreach ($words as $word) {
                $text = trim(html_entity_decode($word[5], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '') {
                    continue;
                }
                $items[] = [
                    'text' => $text,
                    'x' => ((float) $word[1] + (float) $word[3]) / 2,
                    'y' => ((float) $word[2] + (float) $word[4]) / 2,
                    'page' => $number,
                ];
            }

            $pages[] = [
                'page' => $number,
                'width' => $width,
                'height' => $height,
                'texts' => $items,
            ];
        }

        return $pages;
    }

    /**
     * @return list<array{page: int, width: float, height: float, texts: list<array{text: string, x: float, y: float, page: int}>}>
     */
    private function viaSmalot(string $path): array
    {
        if (! is_file($path) || filesize($path) < 1) {
            return [];
        }

        $this->memory->ensureCanParse($path);
        $document = null;
        try {
            $document = (new Parser)->parseFile($path);
            $pages = [];
            foreach (array_values($document->getPages()) as $index => $page) {
                $pages[] = $this->smalotPage($page, $index + 1);
            }

            return $pages;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable) {
            return [];
        } finally {
            unset($document);
            $this->memory->release();
        }
    }

    /**
     * @return array{page: int, width: float, height: float, texts: list<array{text: string, x: float, y: float, page: int}>}
     */
    private function smalotPage(Page $page, int $number): array
    {
        $width = 595.0;
        $height = 842.0;
        try {
            $details = $page->getDetails();
            $box = $details['MediaBox'] ?? [];
            if (is_array($box) && count($box) >= 4) {
                $width = max(1.0, (float) $box[2] - (float) $box[0]);
                $height = max(1.0, (float) $box[3] - (float) $box[1]);
            }
        } catch (\Throwable) {
        }

        $items = [];
        try {
            $rows = $page->getDataTm();
        } catch (\Throwable) {
            $rows = [];
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

        return [
            'page' => $number,
            'width' => $width,
            'height' => $height,
            'texts' => $items,
        ];
    }
}
