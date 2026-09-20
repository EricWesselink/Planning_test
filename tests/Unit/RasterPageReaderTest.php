<?php

namespace Tests\Unit;

use App\Services\AreaWithoutM2Trial\RasterPageReader;
use App\Services\AreaWithoutM2Trial\WallAxisAssembler;
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

    /**
     * @param  callable(\GdImage, int): void  $draw
     */
    private function png(callable $draw): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-wall-'.bin2hex(random_bytes(6)).'.png';
        $image = imagecreatetruecolor(400, 300);
        $this->assertNotFalse($image);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 399, 299, $white);
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
