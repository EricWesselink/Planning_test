<?php

namespace Tests\Unit;

use App\Services\AreaWithoutM2Trial\DimensionChainReconstructor;
use Tests\TestCase;

class DimensionChainReconstructorTest extends TestCase
{
    public function test_horizontal_chain_uses_ratio_fit_and_skips_extra_interior_axes(): void
    {
        $numbers = [
            ['mm' => 3600, 'text' => '3600', 'x' => 140.0, 'y' => 80.0, 'page' => 1],
            ['mm' => 2200, 'text' => '2200', 'x' => 320.0, 'y' => 82.0, 'page' => 1],
            ['mm' => 1800, 'text' => '1800', 'x' => 440.0, 'y' => 78.0, 'page' => 1],
            ['mm' => 3000, 'text' => '3000', 'x' => 580.0, 'y' => 80.0, 'page' => 1],
            ['mm' => 10600, 'text' => '10600', 'x' => 360.0, 'y' => 70.0, 'page' => 1],
        ];
        $lines = [
            ['x1' => 40.0, 'y1' => 200.0, 'x2' => 40.0, 'y2' => 700.0, 'axis' => 'v'],
            ['x1' => 257.0, 'y1' => 200.0, 'x2' => 257.0, 'y2' => 700.0, 'axis' => 'v'],
            ['x1' => 390.0, 'y1' => 200.0, 'x2' => 390.0, 'y2' => 700.0, 'axis' => 'v'],
            ['x1' => 499.0, 'y1' => 200.0, 'x2' => 499.0, 'y2' => 700.0, 'axis' => 'v'],
            ['x1' => 540.0, 'y1' => 200.0, 'x2' => 540.0, 'y2' => 700.0, 'axis' => 'v'],
            ['x1' => 600.0, 'y1' => 200.0, 'x2' => 600.0, 'y2' => 700.0, 'axis' => 'v'],
            ['x1' => 680.0, 'y1' => 200.0, 'x2' => 680.0, 'y2' => 700.0, 'axis' => 'v'],
        ];

        $result = (new DimensionChainReconstructor)->reconstruct($numbers, $lines, 1200.0, 900.0);

        $byMm = collect($result['objects'])->keyBy('mm');
        $this->assertEqualsWithDelta(499.0, $byMm[3000]['endpoint1']['x'], 1.0);
        $this->assertEqualsWithDelta(680.0, $byMm[3000]['endpoint2']['x'], 1.0);
        $this->assertSame('horizontal', $byMm[3000]['orientation']);
        $this->assertTrue($byMm[10600]['overall'] ?? false);
        $chain = collect($result['chains'])->firstWhere('orientation', 'horizontal');
        $this->assertSame([3600, 2200, 1800, 3000], $chain['segments'] ?? null);
        $this->assertSame(10600, $chain['sum'] ?? null);
        $this->assertTrue($chain['valid'] ?? false);
    }

    public function test_vertical_chain_groups_jittered_numbers_and_fits_extension_lines(): void
    {
        $numbers = [
            ['mm' => 3300, 'text' => '3300', 'x' => 1090.0, 'y' => 310.0, 'page' => 1],
            ['mm' => 1200, 'text' => '1200', 'x' => 1125.0, 'y' => 460.0, 'page' => 1],
            ['mm' => 3500, 'text' => '3500', 'x' => 1070.0, 'y' => 620.0, 'page' => 1],
            ['mm' => 8000, 'text' => '8000', 'x' => 1140.0, 'y' => 450.0, 'page' => 1],
        ];
        $lines = [
            ['x1' => 200.0, 'y1' => 198.0, 'x2' => 1055.0, 'y2' => 198.0, 'axis' => 'h'],
            ['x1' => 200.0, 'y1' => 420.0, 'x2' => 1055.0, 'y2' => 420.0, 'axis' => 'h'],
            ['x1' => 200.0, 'y1' => 501.0, 'x2' => 1055.0, 'y2' => 501.0, 'axis' => 'h'],
            ['x1' => 200.0, 'y1' => 560.0, 'x2' => 1055.0, 'y2' => 560.0, 'axis' => 'h'],
            ['x1' => 200.0, 'y1' => 736.0, 'x2' => 1055.0, 'y2' => 736.0, 'axis' => 'h'],
            ['x1' => 200.0, 'y1' => 198.0, 'x2' => 200.0, 'y2' => 736.0, 'axis' => 'v'],
            ['x1' => 1055.0, 'y1' => 198.0, 'x2' => 1055.0, 'y2' => 736.0, 'axis' => 'v'],
        ];

        $result = (new DimensionChainReconstructor)->reconstruct($numbers, $lines, 1200.0, 900.0);

        $byMm = collect($result['objects'])->keyBy('mm');
        $this->assertArrayHasKey(3500, $byMm->all());
        $this->assertArrayHasKey(3300, $byMm->all());
        $this->assertSame('vertical', $byMm[3500]['orientation']);
        $this->assertEqualsWithDelta(501.0, $byMm[3500]['endpoint1']['y'], 1.0);
        $this->assertEqualsWithDelta(736.0, $byMm[3500]['endpoint2']['y'], 1.0);
        $this->assertEqualsWithDelta(198.0, $byMm[3300]['endpoint1']['y'], 1.0);
        $this->assertEqualsWithDelta(420.0, $byMm[3300]['endpoint2']['y'], 1.0);
        $chain = collect($result['chains'])->firstWhere('orientation', 'vertical');
        $this->assertSame([3300, 1200, 3500], $chain['segments'] ?? null);
        $this->assertSame(8000, $chain['total'] ?? null);
        $this->assertTrue($chain['valid'] ?? false);
    }

    public function test_a_lone_number_without_axes_is_not_a_chain(): void
    {
        $result = (new DimensionChainReconstructor)->reconstruct(
            [['mm' => 3500, 'text' => '3500', 'x' => 40.0, 'y' => 40.0, 'page' => 1]],
            [],
            400.0,
            400.0,
        );

        $this->assertSame([], $result['objects']);
        $this->assertSame([], $result['chains']);
    }
}
