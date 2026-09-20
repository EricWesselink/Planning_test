<?php

namespace Tests\Support;

/**
 * Bouwt een PDF zonder tekstlaag (alleen rasterbeeld) voor OCR-tests.
 */
class ImageOnlyPdf
{
    public static function path(string $text): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nicon-imgpdf-');
        if ($path === false) {
            throw new \RuntimeException('Kon tijdelijk PDF-pad niet aanmaken.');
        }
        file_put_contents($path, self::bytes($text));

        return $path;
    }

    public static function bytes(string $text): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $lineHeight = 48;
        $padding = 40;
        $width = 1200;
        $height = max(800, $padding * 2 + count($lines) * $lineHeight);

        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException('GD kon geen afbeelding maken.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 20, 20, 20);
        imagefilledrectangle($image, 0, 0, $width, $height, $white);

        $font = self::fontPath();
        $y = $padding + 36;
        foreach ($lines as $line) {
            if ($font !== null) {
                imagettftext($image, 28, 0, $padding, $y, $black, $font, $line);
            } else {
                // Fallback: schaalbare blocky tekst via imagestring + resize.
                self::drawScaledLine($image, $line, $padding, $y - 28, $black);
            }
            $y += $lineHeight;
        }

        return self::jpegPdf($image);
    }

    /**
     * Scanned plattegrond: gesloten ruimtecontour met lokale maatlijnen, zonder tekstlaag.
     */
    public static function dimensionedRoomPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nicon-imgpdf-');
        if ($path === false) {
            throw new \RuntimeException('Kon tijdelijk PDF-pad niet aanmaken.');
        }
        file_put_contents($path, self::dimensionedRoomBytes());

        return $path;
    }

    public static function dimensionedRoomBytes(): string
    {
        $width = 800;
        $height = 600;
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException('GD kon geen afbeelding maken.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $width, $height, $white);
        imagesetthickness($image, 3);
        imagerectangle($image, 150, 180, 500, 380, $black);

        $font = self::fontPath();
        self::drawLabel($image, 'OPSLAG', 250, 250, $black, $font);
        self::drawLabel($image, 'A-00-03', 250, 295, $black, $font);
        self::drawLabel($image, '99 m2', 250, 340, $black, $font);
        self::drawLabel($image, '3500', 280, 415, $black, $font);
        self::drawLabel($image, '2000', 40, 290, $black, $font);

        return self::jpegPdf($image);
    }

    /**
     * @param  \GdImage  $image
     */
    private static function drawLabel($image, string $text, int $x, int $y, int $color, ?string $font): void
    {
        if ($font !== null) {
            imagettftext($image, 22, 0, $x, $y, $color, $font, $text);

            return;
        }

        self::drawScaledLine($image, $text, $x, $y - 22, $color);
    }

    /**
     * @param  \GdImage  $image
     */
    private static function jpegPdf($image): string
    {
        $jpgPath = tempnam(sys_get_temp_dir(), 'nicon-jpg-');
        if ($jpgPath === false) {
            imagedestroy($image);
            throw new \RuntimeException('Kon tijdelijk JPEG-pad niet aanmaken.');
        }
        imagejpeg($image, $jpgPath, 92);
        imagedestroy($image);

        $img = (string) file_get_contents($jpgPath);
        @unlink($jpgPath);
        $info = getimagesizefromstring($img);
        if ($info === false) {
            throw new \RuntimeException('JPEG-afbeelding is ongeldig.');
        }
        [$w, $h] = $info;

        $content = "q\n{$w} 0 0 {$h} 0 0 cm\n/Im0 Do\nQ\n";
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$w.' '.$h.'] /Contents 4 0 R /Resources << /XObject << /Im0 5 0 R >> >> >> endobj',
            '4 0 obj << /Length '.strlen($content)." >> stream\n".$content.'endstream endobj',
            '5 0 obj << /Type /XObject /Subtype /Image /Width '.$w.' /Height '.$h.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($img)." >> stream\n".$img."\nendstream endobj",
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
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$xref."\n%%EOF\n";

        return $pdf;
    }

    private static function fontPath(): ?string
    {
        foreach ([
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\calibri.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  \GdImage  $image
     */
    private static function drawScaledLine($image, string $line, int $x, int $y, int $color): void
    {
        $tmp = imagecreatetruecolor(max(10, strlen($line) * 8 + 4), 16);
        $bg = imagecolorallocate($tmp, 255, 255, 255);
        $fg = imagecolorallocate($tmp, 20, 20, 20);
        imagefilledrectangle($tmp, 0, 0, imagesx($tmp), imagesy($tmp), $bg);
        imagestring($tmp, 5, 2, 0, $line, $fg);
        imagecopyresized($image, $tmp, $x, $y, 0, 0, imagesx($tmp) * 3, imagesy($tmp) * 3, imagesx($tmp), imagesy($tmp));
        imagedestroy($tmp);
        unset($color);
    }
}
