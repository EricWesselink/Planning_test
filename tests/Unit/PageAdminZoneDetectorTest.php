<?php

namespace Tests\Unit;

use App\Services\AreaWithoutM2Trial\PageAdminZoneDetector;
use Tests\TestCase;

class PageAdminZoneDetectorTest extends TestCase
{
    public function test_clusters_legend_product_codes_away_from_the_floor_plan(): void
    {
        $zones = (new PageAdminZoneDetector)->detect([
            'width' => 600.0,
            'height' => 800.0,
            'texts' => [
                ['text' => 'Legenda', 'x' => 40.0, 'y' => 40.0, 'page' => 1],
                ['text' => 'v01 = Marmoleum - Forbo 3733', 'x' => 42.0, 'y' => 28.0, 'page' => 1],
                ['text' => '3732', 'x' => 80.0, 'y' => 16.0, 'page' => 1],
                ['text' => 'OPSLAG', 'x' => 275.0, 'y' => 610.0, 'page' => 1],
                ['text' => '3500', 'x' => 250.0, 'y' => 485.0, 'page' => 1],
            ],
        ]);

        $detector = new PageAdminZoneDetector;

        $this->assertNotNull($detector->reasonAt(80.0, 16.0, $zones));
        $this->assertNull($detector->reasonAt(250.0, 485.0, $zones));
        $this->assertNull($detector->reasonAt(275.0, 610.0, $zones));
    }
}
