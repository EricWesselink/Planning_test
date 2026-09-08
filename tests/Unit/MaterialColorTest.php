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
}
