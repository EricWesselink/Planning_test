<?php

namespace Tests\Unit;

use App\Services\Meetstaat\FloorLabel;
use Tests\TestCase;

class FloorLabelTest extends TestCase
{
    public function test_canonical_reads_kelder_and_any_storey_number(): void
    {
        $floors = new FloorLabel;

        $this->assertSame('begane grond', $floors->canonical('Bouwlaag: begane grond'));
        $this->assertSame('kelder', $floors->canonical('Kelder'));
        $this->assertSame('kelder', $floors->canonical('souterrain'));
        $this->assertSame('verdieping 1', $floors->canonical('verdieping 1'));
        $this->assertSame('verdieping 3', $floors->canonical('verdieping 3'));
        $this->assertSame('verdieping 3', $floors->canonical('3e verdieping'));
        $this->assertSame('installatie ruimten', $floors->canonical('installatie ruimte'));
        $this->assertNull($floors->canonical('plattegrond'));
    }

    public function test_two_digit_room_prefix_maps_to_storey_not_leading_zero(): void
    {
        $floors = new FloorLabel;

        $this->assertSame('begane grond', $floors->fromRoomNumber('0.07'));
        $this->assertSame('begane grond', $floors->fromRoomNumber('00.01'));
        $this->assertSame('verdieping 1', $floors->fromRoomNumber('1.08'));
        $this->assertSame('verdieping 3', $floors->fromRoomNumber('03.08'));
        $this->assertSame('verdieping 3', $floors->fromRoomNumber('03.13b'));
        $this->assertSame(3, $floors->storeyPrefix('03.08'));
        $this->assertNull($floors->fromRoomNumber('OAT container ruimte'));
    }

    public function test_number_plausible_for_floor_uses_two_digit_storey(): void
    {
        $floors = new FloorLabel;

        $this->assertTrue($floors->numberPlausibleForFloor('03.08', 'verdieping 3'));
        $this->assertFalse($floors->numberPlausibleForFloor('03.08', 'begane grond'));
        $this->assertTrue($floors->numberPlausibleForFloor('0.07', 'begane grond'));
        $this->assertTrue($floors->numberPlausibleForFloor('03.08', 'onbekend'));
        $this->assertTrue($floors->numberPlausibleForFloor('0.01', 'kelder'));
        $this->assertTrue($floors->numberPlausibleForFloor('1.40', 'fase 1 verdieping 1'));
        $this->assertTrue($floors->numberPlausibleForFloor('1.91', 'fase 1 souterrain'));
    }

    public function test_canonical_preserves_fase_prefix_and_souterrain_wording(): void
    {
        $floors = new FloorLabel;

        $this->assertSame('fase 1 verdieping 1', $floors->canonical('fase 1 verdieping 1'));
        $this->assertSame('fase 1 verdieping 1', $floors->canonical('Bouwlaag: fase 1 verdieping 1'));
        $this->assertSame('fase 1 souterrain', $floors->canonical('fase 1 souterrain'));
        $this->assertSame('kelder', $floors->canonical('souterrain'));
        $this->assertSame('verdieping 1', $floors->canonical('verdieping 1'));
    }

    public function test_base_strips_fase_and_sporthal_to_storey(): void
    {
        $floors = new FloorLabel;

        $this->assertSame('verdieping 1', $floors->base('fase 1 verdieping 1'));
        $this->assertSame('kelder', $floors->base('fase 1 souterrain'));
        $this->assertSame('begane grond', $floors->base('begane grond sporthal'));
        $this->assertSame('verdieping 1', $floors->base('verdieping 1 sporthal'));
        $this->assertTrue($floors->sameStorey('fase 1 verdieping 1', 'verdieping 1'));
        $this->assertTrue($floors->sameStorey('fase 1 souterrain', 'kelder'));
        $this->assertFalse($floors->sameStorey('fase 1 verdieping 1', 'begane grond'));
        $this->assertSame('tussenlaag', $floors->canonical('tussenlaag'));
        $this->assertSame('tussenlaag', $floors->canonical('MIDDENLAAG (ENTREE)'));
        $this->assertTrue($floors->sameStorey('tussenlaag', 'middenlaag'));
        $this->assertTrue($floors->sameStorey('TUSSENLAAG', 'MIDDENLAAG (ENTREE)'));
    }
}
