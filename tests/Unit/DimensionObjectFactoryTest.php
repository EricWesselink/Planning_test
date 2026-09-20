<?php

namespace Tests\Unit;

use App\Services\AreaWithoutM2Trial\DimensionObjectFactory;
use App\Services\AreaWithoutM2Trial\PageAdminZoneDetector;
use Tests\TestCase;

class DimensionObjectFactoryTest extends TestCase
{
    public function test_mixed_legend_text_is_excluded_and_a_labelled_chain_becomes_a_dimension_object(): void
    {
        $page = [
            'page' => 1,
            'width' => 600.0,
            'height' => 800.0,
            'texts' => [
                ['text' => 'v01 = Marmoleum - Forbo 3733', 'x' => 40.0, 'y' => 30.0, 'page' => 1],
                ['text' => '3732', 'x' => 48.0, 'y' => 18.0, 'page' => 1],
                ['text' => '1700', 'x' => 250.0, 'y' => 485.0, 'page' => 1],
            ],
            'walls' => [
                ['x1' => 100.0, 'y1' => 500.0, 'x2' => 400.0, 'y2' => 500.0, 'axis' => 'h'],
            ],
            'ticks' => [],
        ];
        $zones = (new PageAdminZoneDetector)->detect($page);

        $result = (new DimensionObjectFactory)->fromPage($page, [], $zones);

        $values = array_column($result['accepted'], 'mm');
        $this->assertContains(1700, $values);
        $this->assertSame('horizontal', collect($result['accepted'])->firstWhere('mm', 1700)['orientation'] ?? null);
        $this->assertNotContains(3733, $values);
        $this->assertNotContains(3732, $values);
        $this->assertContains(3733, array_column($result['excluded'], 'mm'));
        $this->assertContains(3732, array_column($result['excluded'], 'mm'));
        $this->assertSame(
            'getal in product-/legendatekst',
            collect($result['excluded'])->firstWhere('mm', 3733)['reason'] ?? null,
        );
    }

    public function test_a_standalone_number_without_a_measure_line_is_not_a_dimension_object(): void
    {
        $page = [
            'page' => 1,
            'width' => 400.0,
            'height' => 400.0,
            'texts' => [
                ['text' => '3500', 'x' => 40.0, 'y' => 40.0, 'page' => 1],
            ],
            'walls' => [],
            'ticks' => [],
        ];

        $result = (new DimensionObjectFactory)->fromPage($page, [], []);

        $this->assertSame([], $result['accepted']);
        $this->assertSame('geen maatlijn of endpoints', $result['excluded'][0]['reason'] ?? null);
        $this->assertSame(3500, $result['excluded'][0]['mm'] ?? null);
    }

    public function test_collinear_chain_becomes_dimension_objects_from_extension_walls(): void
    {
        $page = [
            'page' => 1,
            'width' => 1200.0,
            'height' => 900.0,
            'texts' => [
                ['text' => '3600', 'x' => 100.0, 'y' => 80.0, 'page' => 1],
                ['text' => '2200', 'x' => 250.0, 'y' => 82.0, 'page' => 1],
                ['text' => '1800', 'x' => 380.0, 'y' => 78.0, 'page' => 1],
                ['text' => '3000', 'x' => 540.0, 'y' => 80.0, 'page' => 1],
                ['text' => '10600', 'x' => 320.0, 'y' => 70.0, 'page' => 1],
                ['text' => 'Legenda', 'x' => 40.0, 'y' => 20.0, 'page' => 1],
                ['text' => 'v01 = Marmoleum - Forbo 3733', 'x' => 42.0, 'y' => 8.0, 'page' => 1],
            ],
            'walls' => [
                ['x1' => 40.0, 'y1' => 200.0, 'x2' => 40.0, 'y2' => 700.0, 'axis' => 'v'],
                ['x1' => 180.0, 'y1' => 200.0, 'x2' => 180.0, 'y2' => 700.0, 'axis' => 'v'],
                ['x1' => 320.0, 'y1' => 200.0, 'x2' => 320.0, 'y2' => 700.0, 'axis' => 'v'],
                ['x1' => 440.0, 'y1' => 200.0, 'x2' => 440.0, 'y2' => 700.0, 'axis' => 'v'],
                ['x1' => 680.0, 'y1' => 200.0, 'x2' => 680.0, 'y2' => 700.0, 'axis' => 'v'],
            ],
            'ticks' => [],
        ];
        $zones = (new PageAdminZoneDetector)->detect($page);

        $result = (new DimensionObjectFactory)->fromPage($page, [], $zones);

        $byMm = collect($result['accepted'])->keyBy('mm');
        $this->assertArrayHasKey(3000, $byMm->all());
        $this->assertArrayHasKey(3600, $byMm->all());
        $this->assertSame('horizontal', $byMm[3000]['orientation']);
        $this->assertSame('maatketting + extension-lines', $byMm[3000]['evidence']);
        $this->assertEqualsWithDelta(440.0, $byMm[3000]['endpoint1']['x'], 1.0);
        $this->assertEqualsWithDelta(680.0, $byMm[3000]['endpoint2']['x'], 1.0);
        $this->assertSame('gebouwmaat', $byMm[10600]['evidence'] ?? null);
        $this->assertNotContains(3733, array_column($result['accepted'], 'mm'));
        $this->assertContains(3733, array_column($result['excluded'], 'mm'));
    }
}
