<?php

namespace App\Services\Meetstaat;

/**
 * Gemeenschappelijke projectkop uit Meetstaat / Materialenstaat e.d.
 * Werknummer blijft altijd een string (geen int-cast).
 */
class ProjectDocumentHeader
{
    /**
     * @return array{
     *     customer_name: ?string,
     *     reference: ?string,
     *     project_name: ?string,
     *     project_number: ?string,
     *     date: ?string,
     *     address: ?string,
     *     postal_code: ?string,
     *     city: ?string
     * }
     */
    public static function empty(): array
    {
        return [
            'customer_name' => null,
            'reference' => null,
            'project_name' => null,
            'project_number' => null,
            'date' => null,
            'address' => null,
            'postal_code' => null,
            'city' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $header
     */
    public static function applyLine(array &$header, string $line): bool
    {
        $line = trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);
        if ($line === '') {
            return false;
        }

        if (preg_match('/^Opdrachtgever\s*:\s*(.+)$/iu', $line, $match)) {
            $header['customer_name'] = trim($match[1]);

            return true;
        }

        if (preg_match('/^Referentie\s*:\s*(.+)$/iu', $line, $match)) {
            // Volledige referentie letterlijk bewaren — nooit de 11P-code (of vergelijkbaar) knippen.
            // Een titelblok plakt soms "Datum :" op dezelfde regel; die hoort niet bij de referentie.
            $reference = trim($match[1]);
            if (preg_match('/^(.*?)\s+Datum\s*:\s*(.+)$/iu', $reference, $parts)) {
                $reference = trim($parts[1]);
                $gluedDate = self::parseDate(trim($parts[2]));
                if ($gluedDate !== null && blank($header['date'] ?? null)) {
                    $header['date'] = $gluedDate;
                }
            }
            $header['reference'] = $reference !== '' ? $reference : null;
            if ($header['reference'] !== null) {
                $header['project_name'] = $header['reference'];
            }

            return true;
        }

        if (preg_match('/^(?:Werknr|Werknummer)\s*:\s*(.+)$/iu', $line, $match)) {
            // Exacte tekst bewaren — nooit als int parsen (leading zeros / lange nummers).
            $number = trim((string) $match[1]);
            $header['project_number'] = $number !== '' ? $number : null;

            return true;
        }

        if (preg_match('/^Datum\s*:\s*(.+)$/iu', $line, $match)) {
            $header['date'] = self::parseDate(trim($match[1]));

            return true;
        }

        return false;
    }

    /**
     * @return array{
     *     customer_name: ?string,
     *     reference: ?string,
     *     project_name: ?string,
     *     project_number: ?string,
     *     date: ?string,
     *     address: ?string,
     *     postal_code: ?string,
     *     city: ?string
     * }
     */
    public static function parseFromText(string $text): array
    {
        $header = self::empty();
        foreach (preg_split("/\n/", $text) ?: [] as $line) {
            self::applyLine($header, (string) $line);
        }

        if ($header['project_name'] === null && filled($header['reference'])) {
            $header['project_name'] = (string) $header['reference'];
        }

        return $header;
    }

    /**
     * @deprecated Referentie wordt niet meer ingekort; blijft voor call-sites die de volledige waarde nodig hebben.
     */
    public static function projectNameFromReference(string $reference): string
    {
        return trim($reference);
    }

    public static function parseDate(string $value): ?string
    {
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $value, $match)) {
            $year = strlen($match[3]) === 2 ? '20'.$match[3] : $match[3];

            return sprintf('%04d-%02d-%02d', (int) $year, (int) $match[2], (int) $match[1]);
        }

        return null;
    }

    public static function normalizeComparable(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return mb_strtolower(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private static function sameHeaderValue(string $field, string $primary, string $other): bool
    {
        if (self::normalizeComparable($primary) === self::normalizeComparable($other)) {
            return true;
        }

        if ($field !== 'reference') {
            return false;
        }

        $other = trim((string) preg_replace('/\s+Datum\s*:.*$/iu', '', $other));
        if (self::normalizeComparable($primary) === self::normalizeComparable($other)) {
            return true;
        }

        if (! str_contains($other, '…') && ! str_contains($other, '...')) {
            return false;
        }

        $primaryCode = self::leadingProjectCode($primary);
        $otherCode = self::leadingProjectCode($other);
        if ($primaryCode === null || $otherCode === null || $primaryCode !== $otherCode) {
            return false;
        }

        $visible = trim((string) preg_replace('/[….]{1,3}.*$/u', '', $other));
        $visibleName = self::normalizeComparable((string) preg_replace('/^'.preg_quote($otherCode, '/').'\s*/iu', '', $visible));
        $primaryName = self::normalizeComparable((string) preg_replace('/^'.preg_quote($primaryCode, '/').'\s*/iu', '', $primary));

        return $visibleName !== '' && str_starts_with($primaryName, $visibleName);
    }

    private static function leadingProjectCode(string $value): ?string
    {
        if (! preg_match('/\b(\d{2}P\d{4,})\b/iu', $value, $match)) {
            return null;
        }

        return mb_strtoupper($match[1]);
    }

    /**
     * @param  array<string, mixed>|null  $primary
     * @param  array<string, mixed>|null  $other
     * @return list<array{field: string, label: string, primary: string, other: string, source: string, message: string}>
     */
    public static function mismatchesAgainstPrimary(?array $primary, ?array $other, string $otherSourceLabel): array
    {
        if (! is_array($primary) || ! is_array($other)) {
            return [];
        }

        $fields = [
            'project_number' => 'Werknummer',
            'reference' => 'Referentie',
        ];
        $mismatches = [];

        foreach ($fields as $key => $label) {
            $primaryValue = trim((string) ($primary[$key] ?? ''));
            $otherValue = trim((string) ($other[$key] ?? ''));
            if ($primaryValue === '' || $otherValue === '') {
                continue;
            }
            if (self::sameHeaderValue($key, $primaryValue, $otherValue)) {
                continue;
            }

            $mismatches[] = [
                'field' => $key,
                'label' => $label,
                'primary' => $primaryValue,
                'other' => $otherValue,
                'source' => $otherSourceLabel,
                'message' => 'Mogelijk bestand van ander project',
            ];
        }

        return $mismatches;
    }
}
