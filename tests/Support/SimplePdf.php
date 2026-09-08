<?php

namespace Tests\Support;

class SimplePdf
{
    public static function path(string $text): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nicon-pdf-');
        file_put_contents($path, self::bytes($text));

        return $path;
    }

    public static function bytes(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $lines = preg_split("/\r\n|\n|\r/", $escaped) ?: [];
        $ops = "BT\n/F1 12 Tf\n50 800 Td\n";
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $ops .= "0 -16 Td\n";
            }
            $ops .= '('.$line.") Tj\n";
        }
        $ops .= "ET\n";

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
