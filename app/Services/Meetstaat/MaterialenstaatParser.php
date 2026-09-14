<?php

namespace App\Services\Meetstaat;

use App\Enums\WorkUnit;
use App\Support\DutchNumber;

class MaterialenstaatParser
{
    public function __construct(private PdfTextExtractor $extractor) {}

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path, ?string $originalName = null): array
    {
        $extracted = $this->extractor->extract($path);
        $parsed = $this->parseText($extracted['text']);
        $parsed['engine'] = $extracted['engine'];
        $parsed['needs_ocr'] = $extracted['needs_ocr'] && ($parsed['works'] ?? []) === [];
        if ($parsed['needs_ocr']) {
            $parsed['warnings'][] = 'Deze materialenstaat heeft geen bruikbare tekstlaag. Controleer de totalen handmatig.';
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseText(string $text): array
    {
        $lines = preg_split("/\n/", $text) ?: [];
        $works = [];
        $warnings = [];
        $current = null;
        $header = ProjectDocumentHeader::empty();

        foreach ($lines as $line) {
            $line = $this->cleanLine($line);
            if ($line === '') {
                continue;
            }

            if (ProjectDocumentHeader::applyLine($header, $line)) {
                continue;
            }

            if ($this->materialCode($line) !== null) {
                $works[] = $this->startCodedMaterial($line);
                $current = array_key_last($works);

                continue;
            }

            $inline = $this->parseInlineProductRow($line);
            if ($inline !== null) {
                $works[] = $inline;
                $current = array_key_last($works);

                continue;
            }

            $total = $this->parseTotal($line);
            if ($total !== null && $current !== null) {
                if ($works[$current]['declared_total'] === null || $total['prefer']) {
                    $works[$current]['declared_total'] = $total['quantity'];
                    $works[$current]['unit'] = $total['unit'];
                }

                continue;
            }

            if ($current !== null && $this->materialCode((string) $works[$current]['name']) !== null) {
                if ($this->isSectionBoundary($line)) {
                    continue;
                }
                if (! $this->looksLikeIndependentProductStart($line)) {
                    $this->appendProductContinuation($works[$current], $line);

                    continue;
                }
            }

            if (! $this->looksLikeProductName($line)) {
                continue;
            }

            $name = $this->cleanProductName($line);
            if ($name === '') {
                continue;
            }

            $works[] = [
                'name' => $name,
                'unit' => $this->unitFromProductName($name),
                'declared_total' => null,
                'calculated_total' => 0.0,
                'source_names' => [$name],
            ];
            $current = array_key_last($works);
        }

        $works = array_values(array_filter(
            $works,
            fn (array $work) => ($work['declared_total'] ?? null) !== null || $work['name'] !== 'Onbekend product'
        ));

        if ($works === [] && trim($text) !== '') {
            $warnings[] = 'Geen materialen of totalen herkend in de materialenstaat.';
        }

        if ($header['project_name'] === null && filled($header['reference'])) {
            $header['project_name'] = (string) $header['reference'];
        }

        return [
            'format' => 'materialenstaat',
            'header' => $header,
            'works' => $works,
            'areas' => [],
            'floors' => [],
            'warnings' => $warnings,
            'uncertain' => [],
            'duplicates' => [],
            'needs_ocr' => false,
        ];
    }

    private function cleanLine(string $line): string
    {
        $line = preg_replace('/[\x{E000}-\x{F8FF}]/u', '', $line) ?? $line;
        $line = str_replace("\xC2\xA0", ' ', $line);

        return trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);
    }

    private function materialCode(string $line): ?string
    {
        if (preg_match('/^V\.(\d{2})\b/u', $line, $match)) {
            return 'V.'.$match[1];
        }
        if (preg_match('/^(\d{2}\.\d{2}\.\d{2}[a-z]?)\b/iu', $line, $match)) {
            return mb_strtolower($match[1]);
        }

        return null;
    }

    private function isSectionBoundary(string $line): bool
    {
        return (bool) preg_match('/^(materialenstaat|materiaalstaat|pagina|totaal|bouwlaag|artikel|opdrachtgever|referentie|werknr|werknummer|datum)\b/iu', $line);
    }

    private function looksLikeIndependentProductStart(string $line): bool
    {
        return (bool) preg_match('/\b(tarkett|desso|ege|marmoleum|sikkens|coral)\b/iu', $line);
    }

    /**
     * @return array<string, mixed>
     */
    private function startCodedMaterial(string $line): array
    {
        $quantity = null;
        $nameLine = $line;
        if (preg_match('/^(.+?)\s+(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/u', $line, $match)) {
            $nameLine = $match[1];
            $quantity = DutchNumber::parse($match[2]);
        }

        $name = $this->cleanProductName($nameLine);

        return [
            'name' => $name,
            'unit' => $this->unitFromProductName($name),
            'declared_total' => $quantity,
            'calculated_total' => 0.0,
            'source_names' => [$name],
        ];
    }

    /**
     * @param  array<string, mixed>  $work
     */
    private function appendProductContinuation(array &$work, string $line): void
    {
        $fragment = trim(preg_replace('/\s+/', ' ', $line) ?? $line, ' ,');
        if ($fragment === '') {
            return;
        }

        $work['name'] = trim($work['name'].' '.$fragment, ' ,');
        $work['source_names'] = [$work['name']];
        $work['unit'] = $this->unitFromProductName($work['name']);
    }

    /**
     * @return array{quantity: float, unit: string, prefer: bool}|null
     */
    private function parseTotal(string $line): ?array
    {
        if (preg_match('/^Netto\s*:\s*(.+)$/iu', $line, $match)) {
            $quantity = DutchNumber::parse($match[1]);
            if ($quantity === null) {
                return null;
            }

            return [
                'quantity' => $quantity,
                'unit' => WorkUnit::SquareMeter->value,
                'prefer' => true,
            ];
        }

        if (preg_match('/^Bruto\s*-\s*Deur\s*:\s*(.+)$/iu', $line, $match)) {
            $quantity = DutchNumber::parse($match[1]);
            if ($quantity === null) {
                return null;
            }

            $unit = str_contains(mb_strtolower($match[1]), 'm1') || str_contains(mb_strtolower($line), ' m')
                ? WorkUnit::LinearMeter->value
                : WorkUnit::SquareMeter->value;
            if (preg_match('/m(?:¹|1)\b/u', $match[1]) || preg_match('/\bm\b/u', $match[1])) {
                $unit = WorkUnit::LinearMeter->value;
            }
            if (preg_match('/m(?:²|2)\b/u', $match[1])) {
                $unit = WorkUnit::SquareMeter->value;
            }

            return [
                'quantity' => $quantity,
                'unit' => $unit,
                'prefer' => false,
            ];
        }

        return null;
    }

    /**
     * MaterialList-regels met product + netto (en optioneel bruto) op één regel.
     *
     * @return array<string, mixed>|null
     */
    private function parseInlineProductRow(string $line): ?array
    {
        if (! preg_match('/^(.+?)\s+(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/u', $line, $match)) {
            return null;
        }

        $name = $this->cleanProductName($match[1]);
        if ($name === '' || ! $this->looksLikeProductLabel($name)) {
            return null;
        }

        $quantity = DutchNumber::parse($match[2]);
        if ($quantity === null) {
            return null;
        }

        return [
            'name' => $name,
            'unit' => $this->unitFromProductName($name),
            'declared_total' => $quantity,
            'calculated_total' => 0.0,
            'source_names' => [$name],
        ];
    }

    private function looksLikeProductName(string $line): bool
    {
        if (preg_match('/^(materialenstaat|materiaalstaat|pagina|totaal|bouwlaag|artikel|opdrachtgever|referentie|werknr|werknummer|datum)\b/iu', $line)) {
            return false;
        }
        if (preg_match('/\d+\s*m(?:²|2|¹|1)?\b/u', $line)) {
            return false;
        }

        return $this->looksLikeProductLabel($line);
    }

    private function looksLikeProductLabel(string $line): bool
    {
        return (bool) preg_match('/tarkett|desso|ege|marmoleum|plint|gietvloer|entreemat|pvc|tapijt|linoleum|coral|coating|vinyl|granit|desert|oak|safe\.?\s*t|directie/i', $line)
            || (bool) preg_match('/^[^,]{2,},\s*[^,]{1,},\s*[^,]{2,}$/', $line);
    }

    private function cleanProductName(string $name): string
    {
        $name = preg_replace('/\b(edit_square|banen|omtrek\s*-?\s*deur|oppervlakte|naden)\b/i', '', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name, ' ,');
    }

    private function unitFromProductName(string $name): string
    {
        return str_contains(mb_strtolower($name), 'plint')
            ? WorkUnit::LinearMeter->value
            : WorkUnit::SquareMeter->value;
    }
}
