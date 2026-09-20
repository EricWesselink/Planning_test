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

    public function test_full_material_codes_keep_distinct_fixed_colors(): void
    {
        $colors = [
            'v01' => '#848482',
            'v01.a' => '#be0032',
            'v01.b' => '#f38400',
            'v01.c' => '#dcd300',
            'v01.d' => '#008856',
            'v01.e' => '#c026d3',
            'v01.f' => '#1d4ed8',
            'v01.g' => '#7c3aed',
            'v02' => '#e68fac',
            'v03' => '#5eead4',
            'v04' => '#8db600',
            'v09' => '#222222',
        ];

        foreach ($colors as $code => $hex) {
            $this->assertSame($hex, MaterialColor::fromCode($code));
            $this->assertSame($hex, MaterialColor::fromCode(mb_strtoupper($code)));
        }

        $values = array_values($colors);
        $this->assertSame(count($values), count(array_unique($values)));

        $codes = array_keys($colors);
        for ($i = 0; $i < count($codes); $i++) {
            for ($j = $i + 1; $j < count($codes); $j++) {
                $this->assertFalse(
                    MaterialColor::hexesMatch($colors[$codes[$i]], $colors[$codes[$j]], 70),
                    $codes[$i].' and '.$codes[$j].' are too close',
                );
            }
        }

        $mapped = ['v01', 'v01.a', 'v01.b', 'v01.c', 'v01.d', 'v01.e', 'v01.f', 'v01.g', 'v02', 'v03', 'v04', 'v05', 'v06', 'v07', 'v08', 'v09', 'v10'];
        $hexes = array_map(fn (string $code): string => MaterialColor::fromCode($code), $mapped);
        $this->assertSame(count($mapped), count(array_unique($hexes)));
    }

    public function test_unknown_full_codes_stay_stable_and_do_not_reuse_a_mapped_color(): void
    {
        $left = MaterialColor::fromCode('v12.z');
        $right = MaterialColor::fromCode('v18.k');

        $this->assertSame($left, MaterialColor::fromCode('V12.Z'));
        $this->assertNotSame($left, $right);
        $this->assertNotSame('#008856', $left);
        $this->assertFalse(MaterialColor::hexesMatch($left, MaterialColor::fromCode('v01.d'), 52));
    }

    public function test_work_colors_follow_material_codes_and_stay_distinct_per_product(): void
    {
        $this->assertSame('v01.a', MaterialColor::codeFromLabel('Marmoleum Walton v01.a, Linoleum'));
        $this->assertSame('#be0032', MaterialColor::forWork('#c4a06a', 'Marmoleum Walton, 3352 berlin red v01.a, Linoleum'));
        $this->assertSame('#f38400', MaterialColor::forWork('#65a30d', 'Plint, wit, Plinten'));
        $this->assertSame('#f38400', MaterialColor::resolve(null, 'Plinten wit'));

        $real = MaterialColor::forWork(null, 'Marmoleum Real, 3120 rosato, Linoleum');
        $walton = MaterialColor::forWork(null, 'Marmoleum Walton, 3352 berlin red, Linoleum');
        $this->assertNotSame($real, $walton);
        $this->assertNotSame('#c4a06a', $real);
        $this->assertNotSame('#c4a06a', $walton);
        $this->assertSame('#c9bc94', MaterialColor::forWork('#c9bc94', 'Marmoleum Real, 3120 rosato, Linoleum'));
    }
}
