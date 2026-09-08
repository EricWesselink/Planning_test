<?php

namespace Tests\Support;

/**
 * Numbered drawing style: room numbers + names + m² + fills (Type B).
 * Intentionally not tied to a project name.
 */
class NumberedFloorPlanPdf
{
    public static function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nicon-numbered-pdf-');
        file_put_contents($path, self::bytes());

        return $path;
    }

    public static function bytes(): string
    {
        $brown = [0.55, 0.50, 0.42];
        $green = [0.45, 0.62, 0.48];

        $rooms = [
            ['0.07', 'groepsruimte', 50.97, $brown],
            ['0.09', 'groepsruimte', 59.00, $brown],
            ['0.12', 'schoolleiding', 19.00, $green],
            ['0.19a', 'administratie', 12.00, $green],
            ['0.24', 'hal', 18.00, $brown],
        ];

        $sums = [];
        foreach ($rooms as $room) {
            $key = implode(',', $room[3]);
            $sums[$key] = ($sums[$key] ?? 0) + $room[2];
        }

        $legend = [
            ['Marmoleum Real', $brown, round($sums[implode(',', $brown)], 2)],
            ['PVC Dark Sand', $green, round($sums[implode(',', $green)], 2)],
        ];

        $ops = "1 0 0 RG\n0.8 w\n";
        $ops .= "BT /F1 11 Tf 1 0 0 1 40 810 Tm (begane grond) Tj ET\n";

        foreach ($rooms as $index => $room) {
            $col = $index % 3;
            $row = intdiv($index, 3);
            $x = 30 + ($col * 180);
            $y = 420 + ((1 - $row) * 140);
            [$r, $g, $b] = $room[3];
            $ops .= self::n($r).' '.self::n($g).' '.self::n($b)." rg\n";
            $ops .= self::n($x, 1).' '.self::n($y, 1)." 150 100 re\nf\n";
            $label = $room[0].' '.$room[1];
            $ops .= 'BT /F1 9 Tf 1 0 0 1 '.self::n($x + 8, 1).' '.self::n($y + 30, 1).' Tm ('.self::escape($label).") Tj ET\n";
            $ops .= 'BT /F1 9 Tf 1 0 0 1 '.self::n($x + 8, 1).' '.self::n($y + 50, 1).' Tm ('.self::escape(self::n($room[2]))." m2) Tj ET\n";
        }

        foreach ($legend as $index => $entry) {
            $x = 30;
            $y = 40 + ((1 - $index) * 20);
            [$r, $g, $b] = $entry[1];
            $ops .= self::n($r).' '.self::n($g).' '.self::n($b)." rg\n";
            $ops .= self::n($x, 1).' '.self::n($y, 1)." 10 10 re\nf\n";
            $label = $entry[0].' '.self::n($entry[2]).' m2';
            $ops .= 'BT /F1 8 Tf 1 0 0 1 '.self::n($x + 16, 1).' '.self::n($y + 2, 1).' Tm ('.self::escape($label).") Tj ET\n";
        }

        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj',
            '4 0 obj << /Length '.strlen($ops).' >> stream'."\n".$ops.'endstream endobj',
            '5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object."\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n";
        $pdf .= 'startxref'."\n".$xref."\n%%EOF\n";

        return $pdf;
    }

    private static function n(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, '.', '');
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
