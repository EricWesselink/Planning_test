<?php

namespace Tests\Unit;

use App\Support\WorkType;
use PHPUnit\Framework\TestCase;

class WorkTypeTest extends TestCase
{
    public function test_extracts_type_from_meetstaat_product_line(): void
    {
        $this->assertSame('Linoleum', WorkType::labelFromName('Marmoleum Real, 3120 rosato, Linoleum'));
        $this->assertSame('Marmoleum Real, 3120 rosato', WorkType::productFromName('Marmoleum Real, 3120 rosato, Linoleum'));
        $this->assertSame('Plinten', WorkType::labelFromName('Plint, wit, Plinten'));
        $this->assertSame('Plint, wit', WorkType::productFromName('Plint, wit, Plinten'));
        $this->assertSame('PVC', WorkType::labelFromName('PVC'));
        $this->assertNull(WorkType::productFromName('PVC'));
        $this->assertSame('Plinten', WorkType::labelFromName('Plinten wit'));
        $this->assertNull(WorkType::productFromName('Plinten wit'));
        $this->assertSame('Gietvloer', WorkType::labelFromName('PU gietvloer kleur n.t.b., Coating'));
        $this->assertSame('PU gietvloer kleur n.t.b.', WorkType::productFromName('PU gietvloer kleur n.t.b., Coating'));
    }

    public function test_keeps_gietvloer_and_coating_as_separate_types(): void
    {
        $this->assertSame('Gietvloer', WorkType::labelFromName('PU gietvloer, Ral 7039 met vlok, Coating'));
        $this->assertSame('Gietvloer', WorkType::labelFromName('PU gietvloer in RAL 7039 (eventueel voorzien va inkoop Amipox)'));
        $this->assertSame('Coating', WorkType::labelFromName('vloercoating op CD vloer, Coating'));
        $this->assertSame('Coating', WorkType::labelFromName('Lijvige Epoxy vloercoating inkoop Amipox'));
        $this->assertNotSame(
            WorkType::labelFromName('PU gietvloer, Ral 7039 met vlok, Coating'),
            WorkType::labelFromName('vloercoating op CD vloer, Coating')
        );
    }

    public function test_requires_priming_leveling_follows_category_defaults(): void
    {
        $this->assertTrue(WorkType::requiresPrimingLeveling('Marmoleum Real, 3120 rosato, Linoleum'));
        $this->assertTrue(WorkType::requiresPrimingLeveling('Linoleum'));
        $this->assertTrue(WorkType::requiresPrimingLeveling('Tarkett pvc Classics-English Oak grege 55, PVC - LVT'));
        $this->assertFalse(WorkType::requiresPrimingLeveling('PU gietvloer Sikkens F2.10.60, Coating'));
        $this->assertFalse(WorkType::requiresPrimingLeveling('PU gietvloer kleur n.t.b.'));
        $this->assertFalse(WorkType::requiresPrimingLeveling('Ege Reform Heritage RF 7133080, Tapijttegels'));
        $this->assertFalse(WorkType::requiresPrimingLeveling('Ege Refor Heritage kamerbreed RF 7133070, Tapijt'));
        $this->assertFalse(WorkType::requiresPrimingLeveling('43.20.02 Coral Brush 5721-hurricane grey, Entreemat Banen'));
        $this->assertFalse(WorkType::requiresPrimingLeveling('Schoonloopmat banen'));
        $this->assertFalse(WorkType::requiresPrimingLeveling('Plinten wit'));
    }

    public function test_hides_room_names(): void
    {
        $this->assertTrue(WorkType::looksLikeRoom('0.07 groepsruimte'));
        $this->assertSame('Vloer', WorkType::labelFromName('0.07 groepsruimte', 'Vloer'));
        $this->assertNull(WorkType::productFromName('0.07 groepsruimte'));
        $this->assertFalse(WorkType::looksLikeRoom('43.20.02 Coral Brush 5721-hurricane grey, Entreemat Banen'));
        $this->assertFalse(WorkType::looksLikeRoom('43.20.01a Emco diplomaat 522 R, zwart, Entreemat'));
        $this->assertFalse(WorkType::looksLikeRoom('43.20.03a Epoxy gietvloer, donkergrijs, Coating'));
        $this->assertSame('Entreemat', WorkType::labelFromName('43.20.02 Coral Brush 5721-hurricane grey, Entreemat Banen'));
        $this->assertSame('Screens', WorkType::labelFromName('BNR 11 Screen H: 1574 mm B: 770 mm'));
        $this->assertTrue(WorkType::isWindowCovering('Screen H: 1700 mm B: 960 mm'));
        $this->assertFalse(WorkType::requiresPrimingLeveling('Screen H: 1700 mm B: 960 mm'));
    }
}
