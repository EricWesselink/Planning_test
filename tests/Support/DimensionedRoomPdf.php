<?php

namespace Tests\Support;

/**
 * Rectangular room with millimetre labels and a printed m² that must not drive the calculation.
 */
class DimensionedRoomPdf
{
    public static function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nicon-dim-pdf-');
        file_put_contents($path, self::bytes());

        return $path;
    }

    public static function bytes(): string
    {
        $ops = "0.90 0.90 0.85 rg\n100 500 350 200 re\nf\n";
        $ops .= "0 0 0 RG\n1 w\n100 500 350 200 re\nS\n";
        $ops .= "BT /F1 10 Tf 1 0 0 1 220 610 Tm (OPSLAG) Tj ET\n";
        $ops .= "BT /F1 10 Tf 1 0 0 1 220 590 Tm (A-00-03) Tj ET\n";
        $ops .= "BT /F1 9 Tf 1 0 0 1 220 570 Tm (99 m2) Tj ET\n";
        $ops .= "BT /F1 9 Tf 1 0 0 1 250 485 Tm (3500) Tj ET\n";
        $ops .= "BT /F1 9 Tf 1 0 0 1 70 590 Tm (2000) Tj ET\n";
        $ops .= "1 w\n50 450 m 545 450 l S\n";
        $ops .= "BT /F1 9 Tf 1 0 0 1 250 455 Tm (6975) Tj ET\n";

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
}
