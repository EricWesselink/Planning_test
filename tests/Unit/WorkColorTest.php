<?php

namespace Tests\Unit;

use App\Support\WorkColor;
use PHPUnit\Framework\TestCase;

class WorkColorTest extends TestCase
{
    public function test_maps_each_onderdeel_to_its_own_color(): void
    {
        $this->assertSame('ondergrond', WorkColor::key('ondergrond', 'Primen & Egaliseren', 'Primen & Egaliseren'));
        $this->assertSame('linoleum', WorkColor::key('vloer|linoleum|m2', 'Linoleum', 'Marmoleum Real, Linoleum'));
        $this->assertSame('plinten', WorkColor::key('plinten|12', 'Plinten', 'Plinten wit'));
        $this->assertSame('pvc', WorkColor::key('vloer|pvc|m2', 'PVC', 'PVC'));
        $this->assertSame('entreemat', WorkColor::key('vloer|entreemat|m2', 'Entreemat', 'Coral Bright, Entreemat'));
        $this->assertSame('entreemat', WorkColor::key('vloer|tapijt|m2', 'Tapijt', '43.20.02 Coral Brush 5730, vulcan black,, Tapijttegels'));
        $this->assertSame('gietvloer', WorkColor::key('vloer|gietvloer|m2', 'Gietvloer', 'PU gietvloer, Ral 7039 met vlok, Coating'));
        $this->assertSame('coating', WorkColor::key('vloer|coating|m2', 'Coating', 'vloercoating op CD vloer, Coating'));
        $this->assertSame('Primen & egaliseren', WorkColor::legendLabel('ondergrond'));
        $this->assertSame('Plinten', WorkColor::legendLabel('plinten'));
    }
}
