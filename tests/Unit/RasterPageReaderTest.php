<?php

namespace Tests\Unit;

use App\Services\AreaWithoutM2Trial\RasterPageReader;
use App\Services\AreaWithoutM2Trial\RoomBoundaryReconstructor;
use App\Services\AreaWithoutM2Trial\WallAxisAssembler;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class RasterPageReaderTest extends TestCase
{
    public function test_traces_a_thick_band_as_one_wall_axis_and_ignores_an_outer_dimension_line(): void
    {
        $path = $this->png(function ($image, int $black): void {
            imagefilledrectangle($image, 80, 50, 81, 250, $black);
            imagefilledrectangle($image, 348, 50, 364, 250, $black);
            imagefilledrectangle($image, 80, 50, 364, 54, $black);
            imagefilledrectangle($image, 80, 246, 364, 250, $black);
            imagefilledrectangle($image, 12, 8, 388, 9, $black);
        });

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $this->assertNotEmpty($traced['wall_extract']['bands_v']);
        $this->assertTrue($this->hasVerticalNear($traced['walls'], 356.0, 12.0));
        $dimension = $this->horizontalNear($traced['wall_extract']['axes'], 291.0, 8.0);
        if ($dimension !== null) {
            $this->assertSame(WallAxisAssembler::ROLE_DIMENSION, $dimension['role']);
        }
    }

    public function test_collapses_a_double_line_facade_to_the_midline(): void
    {
        $path = $this->png(function ($image, int $black): void {
            imagefilledrectangle($image, 70, 40, 71, 260, $black);
            imagefilledrectangle($image, 330, 40, 331, 260, $black);
            imagefilledrectangle($image, 344, 40, 345, 260, $black);
            imagefilledrectangle($image, 70, 40, 345, 41, $black);
            imagefilledrectangle($image, 70, 259, 345, 260, $black);
        });

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $this->assertTrue($this->hasVerticalNear($traced['walls'], 337.5, 6.0));
        $this->assertFalse($this->hasVerticalNear($traced['walls'], 330.0, 1.5) && $this->hasVerticalNear($traced['walls'], 344.0, 1.5));
    }

    public function test_traces_a_thin_right_facade_that_falls_between_sample_columns(): void
    {
        $path = $this->png(function ($image, int $black): void {
            imagefilledrectangle($image, 120, 104, 121, 664, $black);
            imagefilledrectangle($image, 1064, 104, 1064, 664, $black);
            imagefilledrectangle($image, 120, 104, 1064, 105, $black);
            imagefilledrectangle($image, 120, 663, 1064, 664, $black);
        }, 1200, 849);

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $this->assertTrue($this->hasVerticalNear($traced['raw_walls'], 1064.0, 12.0));
        $this->assertTrue($this->hasVerticalNear($traced['walls'], 1064.0, 20.0));
        $this->assertNotEmpty($traced['wall_extract']['vertical_candidates']);
    }

    public function test_joins_interrupted_vertical_ink_into_one_axis(): void
    {
        $path = $this->png(function ($image, int $black): void {
            imagefilledrectangle($image, 300, 40, 300, 53, $black);
            imagefilledrectangle($image, 300, 74, 300, 250, $black);
        });

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $verticals = array_values(array_filter(
            $traced['raw_walls'],
            fn (array $wall): bool => ($wall['axis'] ?? '') === 'v' && abs((float) $wall['x1'] - 300.0) <= 3,
        ));

        $this->assertCount(1, $verticals);
        $this->assertLessThan(60.0, (float) $verticals[0]['y1']);
        $this->assertGreaterThan(240.0, (float) $verticals[0]['y2']);
    }

    public function test_collapses_a_triple_line_facade_to_the_midline(): void
    {
        $path = $this->png(function ($image, int $black): void {
            imagefilledrectangle($image, 70, 40, 71, 260, $black);
            imagefilledrectangle($image, 320, 40, 320, 260, $black);
            imagefilledrectangle($image, 328, 40, 328, 260, $black);
            imagefilledrectangle($image, 336, 40, 336, 260, $black);
            imagefilledrectangle($image, 70, 40, 336, 41, $black);
            imagefilledrectangle($image, 70, 259, 336, 260, $black);
        });

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $wall = $this->verticalNear($traced['walls'], 328.0, 5.0);
        $this->assertNotNull($wall);
        $this->assertSame(WallAxisAssembler::KIND_CLUSTER, $wall['kind'] ?? null);
        $this->assertFalse($this->hasVerticalNear($traced['walls'], 320.0, 1.0));
        $this->assertFalse($this->hasVerticalNear($traced['walls'], 336.0, 1.0));
    }

    public function test_traces_a_thin_bottom_facade_that_falls_between_sample_rows(): void
    {
        $path = $this->png(function ($image, int $black): void {
            imagefilledrectangle($image, 120, 104, 121, 664, $black);
            imagefilledrectangle($image, 1064, 104, 1064, 664, $black);
            imagefilledrectangle($image, 120, 654, 1050, 656, $black);
        }, 1200, 849);

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $wall = $this->horizontalNear($traced['raw_walls'], 194.0, 16.0);
        $this->assertNotNull($wall);
        $this->assertGreaterThan(700.0, (float) $wall['x2'] - (float) $wall['x1']);
        $this->assertTrue($this->hasHorizontalNear($traced['walls'], 194.0, 20.0));
        $this->assertNotEmpty($traced['wall_extract']['horizontal_candidates']);
    }

    public function test_joins_interrupted_horizontal_ink_into_one_axis(): void
    {
        $path = $this->png(function ($image, int $black): void {
            imagefilledrectangle($image, 40, 200, 90, 200, $black);
            imagefilledrectangle($image, 130, 200, 300, 200, $black);
        });

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $horizontals = array_values(array_filter(
            $traced['raw_walls'],
            fn (array $wall): bool => ($wall['axis'] ?? '') === 'h' && abs((float) $wall['y1'] - 100.0) <= 8,
        ));

        $this->assertCount(1, $horizontals);
        $this->assertLessThan(50.0, (float) $horizontals[0]['x1']);
        $this->assertGreaterThan(250.0, (float) $horizontals[0]['x2']);
    }

    public function test_collapses_a_triple_line_horizontal_facade_to_the_midline(): void
    {
        $path = $this->png(function ($image, int $black): void {
            imagefilledrectangle($image, 70, 40, 71, 260, $black);
            imagefilledrectangle($image, 70, 180, 330, 180, $black);
            imagefilledrectangle($image, 70, 188, 330, 188, $black);
            imagefilledrectangle($image, 70, 196, 330, 196, $black);
        });

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $wall = $this->horizontalNear($traced['walls'], 112.0, 8.0);
        $this->assertNotNull($wall);
        $this->assertSame(WallAxisAssembler::KIND_CLUSTER, $wall['kind'] ?? null);
        $this->assertFalse($this->hasHorizontalNear($traced['walls'], 120.0, 1.0));
        $this->assertFalse($this->hasHorizontalNear($traced['walls'], 104.0, 1.0));
    }

    public function test_joins_window_interrupted_horizontal_facade_into_one_axis(): void
    {
        $path = $this->png(function ($image, int $black): void {
            imagefilledrectangle($image, 70, 40, 71, 260, $black);
            imagefilledrectangle($image, 320, 40, 321, 260, $black);
            imagefilledrectangle($image, 80, 220, 140, 220, $black);
            imagefilledrectangle($image, 230, 220, 310, 220, $black);
        });

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $wall = $this->horizontalNear($traced['raw_walls'], 80.0, 8.0);
        $this->assertNotNull($wall);
        $this->assertLessThan(90.0, (float) $wall['x1']);
        $this->assertGreaterThan(300.0, (float) $wall['x2']);
        $this->assertTrue($this->hasHorizontalNear($traced['walls'], 80.0, 12.0));
    }

    public function test_joins_bottom_facade_bands_across_a_large_window_gap(): void
    {
        $path = $this->png(function ($image, int $black): void {
            imagefilledrectangle($image, 120, 104, 121, 664, $black);
            imagefilledrectangle($image, 1064, 104, 1064, 664, $black);
            imagefilledrectangle($image, 480, 650, 546, 651, $black);
            imagefilledrectangle($image, 858, 650, 987, 651, $black);
        }, 1200, 849);

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $wall = $this->horizontalNear($traced['raw_walls'], 199.0, 16.0);
        $this->assertNotNull($wall);
        $this->assertLessThan(500.0, (float) $wall['x1']);
        $this->assertGreaterThan(850.0, (float) $wall['x2']);
        $this->assertTrue($this->hasHorizontalNear($traced['walls'], 199.0, 20.0));
    }

    public function test_does_not_treat_letter_strokes_as_a_horizontal_wall(): void
    {
        $path = $this->png(function ($image, int $black): void {
            imagefilledrectangle($image, 70, 40, 71, 260, $black);
            imagefilledrectangle($image, 320, 40, 321, 260, $black);
            imagefilledrectangle($image, 70, 40, 320, 41, $black);
            for ($x = 90; $x <= 250; $x += 6) {
                imagefilledrectangle($image, $x, 150, $x + 1, 150, $black);
            }
        });

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $this->assertNull($this->horizontalNear($traced['raw_walls'], 150.0, 6.0));
        $this->assertNull($this->horizontalNear($traced['walls'], 150.0, 6.0));
    }

    public function test_traces_a_gray_horizontal_partition_when_dark_ink_is_thin(): void
    {
        $path = $this->png(function ($image, int $black): void {
            $gray = imagecolorallocate($image, 120, 120, 120);
            imagefilledrectangle($image, 70, 40, 71, 260, $black);
            imagefilledrectangle($image, 330, 40, 331, 260, $black);
            imagefilledrectangle($image, 90, 160, 310, 160, $gray);
        });

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $this->assertTrue($this->hasHorizontalNear($traced['raw_walls'], 140.0, 12.0));
        $this->assertTrue($this->hasHorizontalNear($traced['walls'], 140.0, 16.0));
    }

    public function test_stacked_bedrooms_get_a_shared_partition_and_joined_bottom_facade(): void
    {
        $path = $this->png(function ($image, int $black): void {
            $gray = imagecolorallocate($image, 110, 110, 110);
            imagefilledrectangle($image, 678, 103, 679, 664, $black);
            imagefilledrectangle($image, 1055, 103, 1056, 664, $black);
            imagefilledrectangle($image, 678, 108, 1055, 109, $black);
            imagefilledrectangle($image, 787, 372, 1045, 373, $gray);
            imagefilledrectangle($image, 480, 650, 546, 651, $black);
            imagefilledrectangle($image, 858, 650, 987, 651, $black);
            for ($x = 900; $x <= 1020; $x += 6) {
                imagefilledrectangle($image, $x, 228, $x + 1, 228, $black);
                imagefilledrectangle($image, $x, 515, $x + 1, 515, $black);
            }
        }, 1200, 849);

        try {
            $traced = (new RasterPageReader)->tracePng($path);
        } finally {
            @unlink($path);
        }

        $this->assertNull($this->horizontalNear($traced['raw_walls'], 621.0, 8.0));
        $this->assertNull($this->horizontalNear($traced['raw_walls'], 334.0, 8.0));
        $bottom = $this->horizontalNear($traced['walls'], 199.0, 20.0);
        $this->assertNotNull($bottom);
        $this->assertLessThan(500.0, (float) $bottom['x1']);
        $this->assertGreaterThan(850.0, (float) $bottom['x2']);
        $this->assertTrue($this->hasHorizontalNear($traced['walls'], 476.0, 16.0));

        $page = [
            'page' => 1,
            'width' => 1200.0,
            'height' => 849.0,
            'fills' => [],
            'ticks' => [],
            'walls' => $traced['walls'],
            'raw_walls' => $traced['raw_walls'],
            'wall_extract' => $traced['wall_extract'],
        ];
        $anchors = [
            ['x' => 945.3, 'y' => 620.5, 'page' => 1, 'room_key' => 's1', 'text' => 'SLAAPKAMER 1'],
            ['x' => 945.0, 'y' => 333.5, 'page' => 1, 'room_key' => 's2', 'text' => 'SLAAPKAMER 2'],
        ];
        $reconstructor = new RoomBoundaryReconstructor;
        $first = $reconstructor->reconstruct($anchors[0], $page, $anchors);
        $second = $reconstructor->reconstruct($anchors[1], $page, $anchors);

        $this->assertNotNull($first['top_pos']);
        $this->assertNotNull($first['bottom_pos']);
        $this->assertNotNull($second['top_pos']);
        $this->assertNotNull($second['bottom_pos']);
        $this->assertGreaterThan(720.0, (float) $first['top_pos']);
        $this->assertGreaterThan(450.0, (float) $first['bottom_pos']);
        $this->assertLessThan(500.0, (float) $first['bottom_pos']);
        $this->assertGreaterThan(450.0, (float) $second['top_pos']);
        $this->assertLessThan(230.0, (float) $second['bottom_pos']);
        $this->assertEqualsWithDelta((float) $first['bottom_pos'], (float) $second['top_pos'], 16.0);
    }

    public function test_accepts_command_v_pdftoppm_without_statting_usr_bin(): void
    {
        $path = RasterPageReader::resolveBinary(
            'pdftoppm',
            'Linux',
            lookup: fn (string $binary): ?string => $binary === 'pdftoppm' ? '/usr/bin/pdftoppm' : null,
            localFile: function (string $candidate): bool {
                $this->fail('Filesystemstat op '.$candidate.' is verboden buiten open_basedir.');

                return false;
            },
        );

        $this->assertSame('/usr/bin/pdftoppm', $path);
    }

    public function test_does_not_stat_windows_tesseract_paths_on_linux(): void
    {
        $path = RasterPageReader::resolveBinary(
            'tesseract',
            'Linux',
            lookup: fn (): ?string => null,
            localFile: function (): bool {
                $this->fail('Windows Tesseract-paden mogen op Linux niet met is_file worden gecontroleerd.');

                return false;
            },
        );

        $this->assertNull($path);
    }

    public function test_uses_windows_tesseract_install_when_not_on_path(): void
    {
        $windows = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';

        $path = RasterPageReader::resolveBinary(
            'tesseract',
            'Windows',
            lookup: fn (): ?string => null,
            localFile: fn (string $candidate): bool => $candidate === $windows,
        );

        $this->assertSame($windows, $path);
    }

    public function test_reports_a_missing_tesseract_binary_instead_of_throwing(): void
    {
        $reader = new class extends RasterPageReader
        {
            protected function findBinary(string $name): ?string
            {
                return $name === 'pdftoppm' ? '/usr/bin/pdftoppm' : null;
            }
        };

        $path = SimplePdf::path('scan');
        try {
            $result = $reader->read($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame([], $result['pages']);
        $this->assertSame(
            'Tesseract ontbreekt. Installeer deze PDF/OCR-tool om een scan zonder tekstlaag te lezen.',
            $result['error'],
        );
    }

    public function test_reports_missing_pdf_tools_when_binary_lookup_throws(): void
    {
        $reader = new class extends RasterPageReader
        {
            protected function findBinary(string $name): ?string
            {
                throw new \ErrorException('is_file(): open_basedir restriction in effect. File(/usr/bin/pdftoppm) is not within the allowed path(s)');
            }
        };

        $path = SimplePdf::path('scan');
        try {
            $result = $reader->read($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame([], $result['pages']);
        $this->assertSame(
            'pdftoppm en Tesseract ontbreken. Installeer deze PDF/OCR-tools om een scan zonder tekstlaag te lezen.',
            $result['error'],
        );
    }

    /**
     * @param  callable(\GdImage, int): void  $draw
     */
    private function png(callable $draw, int $width = 400, int $height = 300): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-wall-'.bin2hex(random_bytes(6)).'.png';
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $white);
        $draw($image, $black);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     */
    private function hasVerticalNear(array $walls, float $x, float $tol): bool
    {
        return $this->verticalNear($walls, $x, $tol) !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     * @return array<string, mixed>|null
     */
    private function verticalNear(array $walls, float $x, float $tol): ?array
    {
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') !== 'v') {
                continue;
            }
            $pos = (float) ($wall['x1'] ?? $wall['x'] ?? 0);
            if (abs($pos - $x) <= $tol) {
                return $wall;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     */
    private function hasHorizontalNear(array $walls, float $y, float $tol): bool
    {
        return $this->horizontalNear($walls, $y, $tol) !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     * @return array<string, mixed>|null
     */
    private function horizontalNear(array $walls, float $y, float $tol): ?array
    {
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') !== 'h') {
                continue;
            }
            $pos = (float) ($wall['y1'] ?? $wall['y'] ?? 0);
            if (abs($pos - $y) <= $tol) {
                return $wall;
            }
        }

        return null;
    }
}
