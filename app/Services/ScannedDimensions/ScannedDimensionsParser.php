<?php

namespace App\Services\ScannedDimensions;

use App\Support\DutchNumber;

/**
 * Losse parser voor handmatige/afmetingen-PDF’s.
 * Geen Meetstaat-/vloerimport-afhankelijkheden.
 */
class ScannedDimensionsParser
{
    /**
     * @return array{
     *     rooms: list<array{room_number: string, name: string, quantity: float, bruto: ?float, unit: string, status: string}>,
     *     netto_total: float,
     *     bruto_total: ?float,
     *     snijverlies_pct: ?float,
     *     declared_netto_on_pdf: ?float,
     *     warnings: list<string>
     * }
     */
    public function parse(string $text): array
    {
        $text = $this->normalizeOcrSpacing($text);
        $rooms = $this->extractRooms($text);
        $info = $this->extractInfoTotals($text);
        $warnings = [];

        $nettoSum = round(array_sum(array_column($rooms, 'quantity')), 2);
        $declared = $info['netto_total'];
        if ($declared !== null && $rooms !== [] && abs($declared - $nettoSum) > 0.10) {
            $warnings[] = 'Opgegeven netto-totaal ('.$this->formatQty($declared).' m²) wijkt af van som ruimtes ('.$this->formatQty($nettoSum).' m²). Som van ruimtes is leidend.';
        }

        if ($info['bruto_total'] !== null || $info['snijverlies_pct'] !== null) {
            $parts = [];
            if ($info['bruto_total'] !== null) {
                $parts[] = 'bruto '.$this->formatQty($info['bruto_total']).' m²';
            }
            if ($info['snijverlies_pct'] !== null) {
                $parts[] = 'snijverlies '.$this->formatQty($info['snijverlies_pct']).'%';
            }
            $warnings[] = ucfirst(implode(' en ', $parts)).' zijn informatief; planning gebruikt netto '.$this->formatQty($nettoSum).' m².';
        }

        return [
            'rooms' => $rooms,
            'netto_total' => $nettoSum,
            'bruto_total' => $info['bruto_total'],
            'snijverlies_pct' => $info['snijverlies_pct'],
            'declared_netto_on_pdf' => $declared,
            'warnings' => $warnings,
        ];
    }

    public function matches(string $text): bool
    {
        $flat = mb_strtolower($text);
        if (mb_strlen(trim($text)) < 12) {
            return false;
        }
        if (str_contains($flat, 'meetstaat') && str_contains($flat, 'bouwlaag:')) {
            return false;
        }
        if (str_contains($flat, 'materialenstaat') || str_contains($flat, 'material list')) {
            return false;
        }

        foreach ($this->extractRooms($text) as $room) {
            if ((float) $room['quantity'] > 0 && ($room['status'] ?? '') === 'ok') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $knownRoomNumbers
     * @return list<string>
     */
    public function matchedDrawingLabels(string $text, array $knownRoomNumbers): array
    {
        $known = [];
        foreach ($knownRoomNumbers as $number) {
            $normalized = mb_strtolower(trim((string) $number));
            if ($normalized !== '') {
                $known[$normalized] = (string) $number;
            }
        }
        if ($known === []) {
            return [];
        }

        uksort($known, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $found = [];
        foreach ($known as $normalized => $display) {
            $pattern = '/(?<![\w.])'.preg_quote($normalized, '/').'(?![\w.])/iu';
            if (preg_match($pattern, $text)) {
                $found[] = $display;
            }
        }

        usort($found, function (string $a, string $b) {
            $an = preg_replace('/[^0-9]/', '', $a) ?? '';
            $bn = preg_replace('/[^0-9]/', '', $b) ?? '';
            $cmp = ((int) $an) <=> ((int) $bn);

            return $cmp !== 0 ? $cmp : strcmp($a, $b);
        });

        return $found;
    }

    /**
     * @return list<array{room_number: string, name: string, quantity: float, bruto: ?float, unit: string, status: string}>
     */
    private function extractRooms(string $text): array
    {
        $rooms = [];
        $consumedNettos = [];

        $pattern = '/\bruimte\s*([0-9]{1,3}[a-zA-Z]?)\b((?:(?!\bruimte\b).){0,80}?)(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/iu';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $between = $match[2][0];
                if ($this->quantityIsInformational($between, $text, (int) $match[0][1])) {
                    continue;
                }

                $number = $this->normalizeRoomNumber($match[1][0]);
                $qty = DutchNumber::parse($match[3][0]);
                if ($number === '' || $qty === null || $qty <= 0 || isset($rooms[$number])) {
                    continue;
                }

                $start = (int) $match[0][1];
                $end = $start + strlen($match[0][0]);
                $consumedNettos[$end] = true;
                $rooms[$number] = $this->roomRow($number, $qty, $this->brutoBetween($text, $start, $this->nextRoomOffset($text, $end)), 'ok');
            }
        }

        foreach ($this->roomLabels($text) as $label) {
            $number = $label['number'];
            if (isset($rooms[$number])) {
                continue;
            }

            $netto = $this->nettoAfter($text, $label['offset'], $this->nextRoomOffset($text, $label['offset'] + 1), $consumedNettos);
            $bruto = $this->brutoBetween($text, $label['offset'], $this->nextRoomOffset($text, $label['offset'] + 1));
            if ($netto !== null && $netto > 0) {
                $rooms[$number] = $this->roomRow($number, $netto, $bruto, 'ok');
            } else {
                $rooms[$number] = $this->roomRow($number, 0.0, $bruto, 'controleren');
            }
        }

        if ($rooms === [] && preg_match('/\bruimte\b/iu', $text)) {
            $compact = '/(?<![\w.])([0-9]{1,3}[a-zA-Z]?)\s*[=:]\s*(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/iu';
            if (preg_match_all($compact, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $number = $this->normalizeRoomNumber($match[1]);
                    $qty = DutchNumber::parse($match[2]);
                    if ($number === '' || $qty === null || $qty <= 0 || isset($rooms[$number])) {
                        continue;
                    }
                    $rooms[$number] = $this->roomRow($number, round($qty, 2), null, 'ok');
                }
            }
        }

        $list = array_values($rooms);
        usort($list, function (array $a, array $b) {
            $an = preg_replace('/[^0-9]/', '', $a['room_number']) ?? '';
            $bn = preg_replace('/[^0-9]/', '', $b['room_number']) ?? '';
            $cmp = ((int) $an) <=> ((int) $bn);

            return $cmp !== 0 ? $cmp : strcmp($a['room_number'], $b['room_number']);
        });

        return $list;
    }

    /**
     * @return array{room_number: string, name: string, quantity: float, bruto: ?float, unit: string, status: string}
     */
    private function roomRow(string $number, float $quantity, ?float $bruto, string $status): array
    {
        $quantity = round($quantity, 2);
        if ($quantity <= 0) {
            $status = 'controleren';
        }

        return [
            'room_number' => $number,
            'name' => 'Ruimte '.$number,
            'quantity' => $quantity,
            'bruto' => $bruto,
            'unit' => 'm2',
            'status' => $status,
        ];
    }

    /**
     * @return list<array{number: string, offset: int}>
     */
    private function roomLabels(string $text): array
    {
        $labels = [];
        $seen = [];
        if (! preg_match_all('/\bruimte\s*([0-9]{1,3}[a-zA-Z]?)\b/iu', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches as $match) {
            $number = $this->normalizeRoomNumber($match[1][0]);
            if ($number === '' || isset($seen[$number])) {
                continue;
            }
            $seen[$number] = true;
            $labels[] = [
                'number' => $number,
                'offset' => (int) $match[0][1],
            ];
        }

        return $labels;
    }

    /**
     * @param  array<int, bool>  $consumedNettos
     */
    private function nettoAfter(string $text, int $start, int $end, array &$consumedNettos): ?float
    {
        $window = substr($text, $start, max(0, $end - $start));
        if (! preg_match_all('/\bnetto\b[^0-9]{0,20}(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/iu', $window, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches as $match) {
            $absolute = $start + (int) $match[0][1];
            if (isset($consumedNettos[$absolute])) {
                continue;
            }
            $before = mb_strtolower(substr($window, max(0, (int) $match[0][1] - 16), 16));
            if (str_contains($before, 'totaal')) {
                continue;
            }
            $qty = DutchNumber::parse($match[1][0]);
            if ($qty === null || $qty <= 0) {
                continue;
            }
            $consumedNettos[$absolute] = true;

            return round($qty, 2);
        }

        return null;
    }

    private function brutoBetween(string $text, int $start, int $end): ?float
    {
        $window = substr($text, $start, max(0, $end - $start));
        if (! preg_match('/\bbruto\b[^0-9]{0,20}(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/iu', $window, $match)) {
            return null;
        }

        $qty = DutchNumber::parse($match[1]);

        return $qty !== null && $qty > 0 ? round($qty, 2) : null;
    }

    private function nextRoomOffset(string $text, int $after): int
    {
        if (preg_match('/\bruimte\s*[0-9]{1,3}[a-zA-Z]?\b/iu', $text, $match, PREG_OFFSET_CAPTURE, $after)) {
            return (int) $match[0][1];
        }

        return strlen($text);
    }

    private function quantityIsInformational(string $between, string $text, int $offset): bool
    {
        $flat = mb_strtolower($between);
        if (preg_match('/\b(bruto|snijverlies|snij|baanlengte|baan)\b/u', $flat) && ! preg_match('/\bnetto\b/u', $flat)) {
            return true;
        }

        $before = mb_strtolower(mb_substr($text, max(0, $offset - 24), 24));

        return str_contains($before, 'totaal') || str_contains($before, 'snij');
    }

    /**
     * @return array{netto_total: ?float, bruto_total: ?float, snijverlies_pct: ?float}
     */
    private function extractInfoTotals(string $text): array
    {
        $netto = null;
        $bruto = null;
        $snij = null;

        if (preg_match('/\btotaal\s*netto\b[^0-9]{0,20}(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/iu', $text, $match)
            || preg_match('/\bnetto\s*totaal\b[^0-9]{0,20}(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/iu', $text, $match)) {
            $netto = DutchNumber::parse($match[1]);
        }

        $brutoCandidates = [];
        if (preg_match_all('/\b(?:totaal\s*)?bruto\b[^0-9]{0,20}(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/iu', $text, $matches)) {
            foreach ($matches[1] as $raw) {
                $value = DutchNumber::parse($raw);
                if ($value !== null && $value > 0) {
                    $brutoCandidates[] = $value;
                }
            }
        }
        if ($brutoCandidates !== []) {
            $bruto = max($brutoCandidates);
        }

        if (preg_match('/\bsnijverlies\b[^0-9%]{0,20}(\d+(?:[.,]\d+)?)\s*%/iu', $text, $match)) {
            $snij = DutchNumber::parse($match[1]);
        }

        return [
            'netto_total' => $netto,
            'bruto_total' => $bruto,
            'snijverlies_pct' => $snij,
        ];
    }

    private function normalizeOcrSpacing(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/m\s*[²2]/u', 'm²', $text) ?? $text;

        return $text;
    }

    private function normalizeRoomNumber(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,3})([a-zA-Z])?$/u', $value, $match)) {
            return $match[1].strtoupper($match[2] ?? '');
        }

        return mb_strtoupper($value);
    }

    private function formatQty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
