<?php

namespace Tests\Unit;

use App\Services\RoomMarkerMatcher;
use Tests\TestCase;

class RoomMarkerMatcherTest extends TestCase
{
    public function test_matches_room_numbers_including_letter_suffix(): void
    {
        $matcher = new RoomMarkerMatcher;

        $this->assertSame(1.0, $matcher->confidence('0.07', '0.07'));
        $this->assertSame(1.0, $matcher->confidence('0.19a', '0.19a'));
        $this->assertSame(0.82, $matcher->confidence('0.09', '0.09 groepsruimte'));
        $this->assertSame(1.0, $matcher->score('0.09', 'groepsruimte', '0.09 groepsruimte'));
        $this->assertNull($matcher->confidence('0.12', '0.120'));
        $this->assertSame('0.24', $matcher->normalize('0,24'));
        $this->assertSame(1.0, $matcher->score('p.0.17', 'theorielokaal', 'P.0.17 theorielokaal'));
        $this->assertSame(1.0, $matcher->score('P.0.17', 'theorielokaal', 'P. 0.17 theorielokaal'));
        $this->assertNull($matcher->confidence('p.0.17', 'P.0.170 berging'));
        $this->assertNull($matcher->confidence('p.0.17', 'P.0.17a kantoor'));
        $this->assertNull($matcher->confidence('p.0.17', 'P.0.18 wasruimte'));
        $this->assertSame(1.0, $matcher->score('p.0.18', 'wasruimte', 'P.0.18 wasruimte'));
        $this->assertSame('0.17', $matcher->coreNumber('P.0.17'));
    }

    public function test_does_not_partial_match_adjacent_room_numbers(): void
    {
        $matcher = new RoomMarkerMatcher;

        $this->assertSame(1.0, $matcher->confidence('1.17', '1.17'));
        $this->assertSame(1.0, $matcher->confidence('1.19', '1.19'));
        $this->assertSame(1.0, $matcher->confidence('1.20', '1.20'));
        $this->assertSame(1.0, $matcher->score('1.17', 'kreeften', '1.17 kreeften'));

        $this->assertNull($matcher->confidence('1.17', '1.19'));
        $this->assertNull($matcher->confidence('1.19', '1.20'));
        $this->assertNull($matcher->confidence('1.19', '1.19a'));
        $this->assertNull($matcher->confidence('1.19a', '1.19'));
        $this->assertNull($matcher->confidence('1.1', '1.17'));
        $this->assertNull($matcher->confidence('1.2', '1.20'));
        $this->assertNull($matcher->confidence('0.19', '0.19a'));
        $this->assertNull($matcher->confidence('0.19a', '0.19b'));
        $this->assertNull($matcher->confidence('0.19b', '0.19a'));
    }

    public function test_recovers_doubled_pdf_text_as_room_label(): void
    {
        $matcher = new RoomMarkerMatcher;
        $garbled = '00.0.077 gr grooeepspsrruuiimmtete';

        $this->assertContains('0.07', $matcher->recoveredNumbers($garbled));
        $this->assertTrue($matcher->nameMatches('groepsruimte', $garbled));
        $this->assertSame(1.0, $matcher->score('0.07', 'groepsruimte', $garbled));
        $this->assertNull($matcher->score('0.09', 'groepsruimte', $garbled));
        $this->assertNull($matcher->score('0.077', 'groepsruimte', $garbled));
    }

    public function test_ground_floor_labels_match_exact_rooms(): void
    {
        $matcher = new RoomMarkerMatcher;

        $this->assertSame(1.0, $matcher->score('0.07', 'groepsruimte', '0.07 groepsruimte'));
        $this->assertSame(1.0, $matcher->score('0.09', 'groepsruimte', '0.09 groepsruimte'));
        $this->assertSame(1.0, $matcher->score('0.12', 'schoolleiding', '0.12 schoolleiding'));
        $this->assertSame(1.0, $matcher->score('0.13', 'algemeen', '0.13 algemeen'));
        $this->assertSame(1.0, $matcher->score('0.19a', 'administratie', '0.19a administratie'));
        $this->assertNull($matcher->score('0.19b', 'administratie', '0.19a administratie'));
        $this->assertNull($matcher->score('0.07', 'groepsruimte', '0.09 groepsruimte'));
        $this->assertTrue($matcher->nameMatches('leerplein OB', '0.10 leerplein OB'));
        $this->assertFalse($matcher->nameMatches('entree', '0.10 leerplein OB'));
    }

    public function test_clusters_name_with_nearby_square_meters(): void
    {
        $matcher = new RoomMarkerMatcher;
        $rooms = $matcher->drawingRoomsFromItems([
            ['page' => 1, 'text' => 'Begane grond', 'x' => 0.82, 'y' => 0.06],
            ['page' => 1, 'text' => 'KDV slaapkamer', 'x' => 0.22, 'y' => 0.40, 'w' => 0.10, 'h' => 0.016],
            ['page' => 1, 'text' => '4,06 m²', 'x' => 0.22, 'y' => 0.42, 'w' => 0.05, 'h' => 0.014],
        ]);

        $this->assertCount(1, $rooms);
        $this->assertSame('kdv slaapkamer', $rooms[0]['room_name']);
        $this->assertEqualsWithDelta(4.06, $rooms[0]['square_meters'], 0.001);
        $this->assertEqualsWithDelta(0.22, $rooms[0]['x'], 0.0001);
        $this->assertSame('begane grond', mb_strtolower($rooms[0]['floor']));
    }
}
