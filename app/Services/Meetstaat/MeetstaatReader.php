<?php

namespace App\Services\Meetstaat;

use App\Services\Meetstaat\Contracts\MeetstaatFormatParser;
use App\Services\Meetstaat\Formats\NiconMeetbonParser;

class MeetstaatReader
{
    /** @param list<MeetstaatFormatParser> $formats */
    public function __construct(
        private PdfTextExtractor $extractor,
        private array $formats = [],
    ) {
        if ($this->formats === []) {
            $this->formats = [new NiconMeetbonParser];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path, ?string $originalName = null): array
    {
        if (! $this->isPdf($path, $originalName)) {
            throw new \InvalidArgumentException('Alleen PDF wordt door de meetstaat-lezer ondersteund.');
        }

        $extracted = $this->extractor->extract($path);
        $parsed = $this->parseText($extracted['text']);
        $parsed['engine'] = $extracted['engine'];
        $parsed['needs_ocr'] = $extracted['needs_ocr'];
        if ($extracted['needs_ocr']) {
            $parsed['warnings'][] = 'Deze PDF heeft geen bruikbare tekstlaag. OCR volgt later; exporteer de meetstaat als Excel of CSV.';
        }

        return $parsed;
    }

    private function isPdf(string $path, ?string $originalName): bool
    {
        $fromName = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
        $fromPath = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($fromName === 'pdf' || $fromPath === 'pdf') {
            return true;
        }

        if (! is_readable($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = (string) fread($handle, 5);
        fclose($handle);

        return str_starts_with($header, '%PDF');
    }

    /**
     * @return array<string, mixed>
     */
    public function parseText(string $text): array
    {
        foreach ($this->formats as $format) {
            if ($format->matches($text)) {
                $parsed = $format->parse($text);
                $parsed['format'] = $format->name();

                return $parsed;
            }
        }

        return [
            'format' => null,
            'header' => [
                'customer_name' => null,
                'reference' => null,
                'project_name' => null,
                'project_number' => null,
                'date' => null,
            ],
            'works' => [],
            'areas' => [],
            'floors' => [],
            'warnings' => ['Dit PDF-formaat is nog niet herkend. De parser is modulair; dit type meetstaat kan later worden toegevoegd.'],
            'uncertain' => [],
            'duplicates' => [],
            'needs_ocr' => false,
        ];
    }
}
