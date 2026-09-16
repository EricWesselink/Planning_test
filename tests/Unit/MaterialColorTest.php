<?php

namespace Tests\Unit;

use App\Support\MaterialColor;
use PHPUnit\Framework\TestCase;

class MaterialColorTest extends TestCase
{
    public function test_prefers_stored_legend_hex(): void
    {
        $this->assertSame('#8d8676', MaterialColor::resolve('#8d8676', 'Dark Sand'));
        $this->assertSame('#5a90ba', MaterialColor::resolve('5a90ba', 'Something else'));
    }

    public function test_maps_known_product_names_deterministically(): void
    {
        $this->assertSame('#8d8676', MaterialColor::resolve(null, 'Dark Sand'));
        $this->assertSame('#9b5b5b', MaterialColor::resolve(null, 'Desso Desert'));
        $this->assertSame('#c9c985', MaterialColor::resolve(null, 'dark warm grey'));
        $this->assertSame('#5a90ba', MaterialColor::resolve(null, 'aqua blue'));
        $this->assertSame('#c4a484', MaterialColor::resolve(null, 'English Oak Classics'));
        $this->assertSame('#c4a484', MaterialColor::resolve(null, 'IVC Ultimo Chapman Oak, 24245, PVC - LVT'));
    }

    public function test_unknown_materials_use_neutral_gray(): void
    {
        $this->assertSame(MaterialColor::UNKNOWN, MaterialColor::resolve(null, 'Onbekend product XYZ'));
        $this->assertSame(MaterialColor::UNKNOWN, MaterialColor::resolve('not-a-color', null));
        $this->assertSame(MaterialColor::UNKNOWN, MaterialColor::resolve(null, null));
    }

    public function test_soft_background_keeps_text_readable(): void
    {
        $soft = MaterialColor::softBackground('#8d8676', 0.14);
        $this->assertStringStartsWith('rgba(141, 134, 118,', $soft);
    }

    public function test_hexes_match_within_tolerance(): void
    {
        $this->assertTrue(MaterialColor::hexesMatch('#db4ddf', '#db4ddf'));
        $this->assertTrue(MaterialColor::hexesMatch('#db4ddf', '#d94cdc'));
        $this->assertFalse(MaterialColor::hexesMatch('#db4ddf', '#c4a484'));
    }

    public function test_from_code_is_stable_and_differs_per_code(): void
    {
        $this->assertSame(MaterialColor::fromCode('v04'), MaterialColor::fromCode('V04'));
        $this->assertSame(MaterialColor::fromCode('v01.e'), MaterialColor::fromCode('v01.e'));
        $this->assertNotSame(MaterialColor::fromCode('v04'), MaterialColor::fromCode('v09'));
        $this->assertNotSame(MaterialColor::fromCode('v02'), MaterialColor::fromCode('v04'));
        $this->assertSame(MaterialColor::UNKNOWN, MaterialColor::fromCode(null, null));
        $this->assertSame('#d4d6c2', MaterialColor::fromCode(null, 'Gietvloer'));
    }
}
