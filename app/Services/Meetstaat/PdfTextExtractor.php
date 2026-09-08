<?php

namespace App\Services\Meetstaat;

use Smalot\PdfParser\Parser;

class PdfTextExtractor
{
    /**
     * @return array{text: string, engine: string, needs_ocr: bool}
     */
    public function extract(string $path): array
    {
        return $this->extractWith($path, 'meetstaat');
    }

    /**
     * @return array{text: string, engine: string, needs_ocr: bool}
     */
    public function extractDrawing(string $path): array
    {
        return $this->extractWith($path, 'drawing');
    }

    /**
     * @return array{text: string, engine: string, needs_ocr: bool}
     */
    private function extractWith(string $path, string $mode): array
    {
        $smalot = $this->viaSmalot($path);
        $engine = 'smalot';
        $text = $smalot;

        if ($this->tooWeak($text, $mode)) {
            $poppler = $this->viaPdftotext($path);
            if (strlen($poppler) > strlen($text)) {
                $text = $poppler;
                $engine = 'pdftotext';
            }
        }

        $text = $this->normalize($text);

        return [
            'text' => $text,
            'engine' => $engine,
            'needs_ocr' => $this->tooWeak($text, $mode),
        ];
    }

    private function viaSmalot(string $path): string
    {
        try {
            $pdf = (new Parser)->parseFile($path);

            return (string) $pdf->getText();
        } catch (\Throwable) {
            return '';
        }
    }

    private function viaPdftotext(string $path): string
    {
        $binary = $this->pdftotextBinary();
        if ($binary === null) {
            return '';
        }

        $output = $path.'.txt';
        $command = escapeshellarg($binary).' -layout '.escapeshellarg($path).' '.escapeshellarg($output).' 2>NUL';
        exec($command, $_, $code);
        if ($code !== 0 || ! is_file($output)) {
            return '';
        }

        $text = (string) file_get_contents($output);
        @unlink($output);

        return $text;
    }

    private function pdftotextBinary(): ?string
    {
        $which = trim((string) shell_exec('where pdftotext 2>NUL'));
        if ($which !== '') {
            return explode("\n", str_replace("\r", '', $which))[0];
        }

        return null;
    }

    private function tooWeak(string $text, string $mode = 'meetstaat'): bool
    {
        $flat = mb_strtolower($text);
        if ($mode === 'drawing') {
            return mb_strlen(trim($text)) < 40
                || ! preg_match('/\d{1,2}[.\-]\d{1,3}[a-z]?/u', $flat);
        }

        return mb_strlen(trim($text)) < 200
            || (! str_contains($flat, 'bouwlaag') && ! str_contains($flat, 'meetstaat'));
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/edit_square/i', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return $text;
    }
}
