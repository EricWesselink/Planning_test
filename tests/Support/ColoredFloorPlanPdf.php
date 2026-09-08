<?php

namespace Tests\Support;

class ColoredFloorPlanPdf
{
    /**
     * Named-room drawing style: rooms with fill colors and a legend (Type A).
     */
    public static function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nicon-color-pdf-');
        file_put_contents($path, self::bytes());

        return $path;
    }

    public static function bytes(): string
    {
        $brown = [0.55, 0.50, 0.42];
        $purple = [0.73, 0.55, 0.72];
        $yellow = [0.78, 0.80, 0.52];
        $red = [0.75, 0.28, 0.22];
        $beige = [0.86, 0.78, 0.62];

        $rooms = [
            ['tekenlokaal', 90.20, $brown],
            ['magazijn tekenen', 38.19, $brown],
            ['tekenen', 91.84, $brown],
            ['handvaardigheid', 109.04, $brown],
            ['berging hv', 12.50, $brown],
            ['aula', 80.00, $brown],
            ['lokaal noord', 44.00, $brown],
            ['kunstplein', 29.20, $purple],
            ['kunstplein', 51.96, $purple],
            ['lokaal zuid', 33.00, $purple],
            ['muziek', 108.06, $yellow],
            ['muziek', 90.09, $yellow],
            ['overloop', 22.00, $yellow],
            ['studio', 15.96, $red],
            ['studio', 18.40, $red],
            ['werkruimte', 15.96, $red],
            ['spoelkeuken', 6.14, $beige],
            ['spoelkeuken', 36.99, $beige],
            ['berging theater', 26.35, $beige],
            ['sanitair', 8.10, $beige],
            ['berging', 5.00, $beige],
            ['berging trap', 4.20, $beige],
        ];

        $sums = [];
        foreach ($rooms as $room) {
            $key = implode(',', $room[2]);
            $sums[$key] = ($sums[$key] ?? 0) + $room[1];
        }

        $legend = [
            ['Tarkett safe.t Granit Dark Sand', $brown, round($sums[implode(',', $brown)], 2)],
            ['Tarkett vinyl iQ Natural-pink clay', $purple, round($sums[implode(',', $purple)], 2)],
            ['Tarkett vinyl iQ Natural-dark warm', $yellow, round($sums[implode(',', $yellow)], 2)],
            ['vloercoating', $red, 200.00],
            ['Tarkett pvc Classics-English Oak', $beige, round($sums[implode(',', $beige)], 2)],
        ];

        $ops = "1 0 0 RG\n0.8 w\n";
        $ops .= "BT /F1 11 Tf 1 0 0 1 40 810 Tm (begane grond) Tj ET\n";

        foreach ($rooms as $index => $room) {
            $col = $index % 4;
            $row = intdiv($index, 4);
            $x = 20 + ($col * 145);
            $y = 200 + ((5 - $row) * 95);
            [$r, $g, $b] = $room[2];
            $ops .= self::n($r).' '.self::n($g).' '.self::n($b)." rg\n";
            $ops .= self::n($x, 1).' '.self::n($y, 1)." 130 80 re\nf\n";
            $ops .= 'BT /F1 8 Tf 1 0 0 1 '.self::n($x + 8, 1).' '.self::n($y + 22, 1).' Tm ('.self::escape($room[0]).") Tj ET\n";
            $ops .= 'BT /F1 8 Tf 1 0 0 1 '.self::n($x + 8, 1).' '.self::n($y + 40, 1).' Tm ('.self::escape(self::n($room[1]))." m2) Tj ET\n";
        }

        foreach ($legend as $index => $entry) {
            $x = 20;
            $y = 24 + ((4 - $index) * 16);
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
