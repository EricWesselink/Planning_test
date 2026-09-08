<?php

namespace App\Services;

class SpreadsheetReader
{
    /** @return list<list<string>> */
    public function rows(string $path, ?string $originalFilename = null): array
    {
        $extension = $this->resolveExtension($path, $originalFilename);

        return match ($extension) {
            'xlsx', 'xlsm' => $this->fromXlsx($path),
            default => $this->fromCsv($path),
        };
    }

    private function resolveExtension(string $path, ?string $originalFilename): string
    {
        $fromName = strtolower(pathinfo((string) $originalFilename, PATHINFO_EXTENSION));
        if (in_array($fromName, ['xlsx', 'xlsm', 'csv', 'txt', 'xls'], true)) {
            return $fromName;
        }

        $fromPath = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($fromPath, ['xlsx', 'xlsm', 'csv', 'txt', 'xls'], true)) {
            return $fromPath;
        }

        if ($this->isZipContainer($path)) {
            return 'xlsx';
        }

        return $fromPath;
    }

    private function isZipContainer(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, 4);
        fclose($handle);

        return str_starts_with($magic, 'PK');
    }

    /** @return list<list<string>> */
    public function fromCsv(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return [];
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $contents = str_replace("\xEF\xBB\xBF", '', $contents);
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        $firstLine = strtok($contents, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        if (substr_count($firstLine, "\t") > substr_count($firstLine, $delimiter)) {
            $delimiter = "\t";
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(static fn ($cell) => trim((string) $cell), $row);
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> */
    public function fromXlsx(string $path): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Excel-bestand kon niet worden geopend.');
        }

        $shared = $this->sharedStrings($zip);
        $sheetName = $this->firstSheetPath($zip);
        $xml = $zip->getFromName($sheetName);
        $zip->close();

        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $sheet = @simplexml_load_string($xml);
        if ($sheet === false) {
            return [];
        }

        $rowsXml = $sheet->xpath('//*[local-name()="row"]') ?: [];
        $rows = [];

        foreach ($rowsXml as $rowXml) {
            $cells = [];
            foreach ($rowXml->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                $ref = (string) $cell['r'];
                $index = $this->columnIndex($ref);
                $cells[$index] = $this->cellValue($cell, $shared);
            }

            if ($cells === []) {
                $rows[] = [];

                continue;
            }

            $max = max(array_keys($cells));
            $row = array_fill(0, $max + 1, '');
            foreach ($cells as $index => $value) {
                $row[$index] = $value;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<string> */
    private function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }

        $items = $doc->xpath('//*[local-name()="si"]') ?: [];
        $strings = [];

        foreach ($items as $item) {
            $parts = $item->xpath('.//*[local-name()="t"]') ?: [];
            $strings[] = trim(implode('', array_map(static fn ($node) => (string) $node, $parts)));
        }

        return $strings;
    }

    private function firstSheetPath(\ZipArchive $zip): string
    {
        $candidates = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $candidates[] = $name;
            }
        }

        sort($candidates, SORT_NATURAL);

        return $candidates[0] ?? 'xl/worksheets/sheet1.xml';
    }

    private function columnIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/i', $ref, $match);
        $letters = strtoupper($match[1] ?? 'A');
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /** @param list<string> $shared */
    private function cellValue(\SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) $cell['t'];

        if ($type === 's') {
            $index = (int) (string) $cell->v;

            return $shared[$index] ?? '';
        }

        if ($type === 'inlineStr') {
            $parts = $cell->xpath('.//*[local-name()="t"]') ?: [];

            return trim(implode('', array_map(static fn ($node) => (string) $node, $parts)));
        }

        $value = (string) ($cell->v ?? '');

        return trim($value);
    }
}
