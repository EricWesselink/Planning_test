<?php

namespace Tests\Unit;

use App\Services\ScannedDimensions\ScannedDimensionsParser;
use Tests\TestCase;

class ScannedDimensionsParserTest extends TestCase
{
    public function test_parses_example_rooms_with_netto_leading(): void
    {
        $text = <<<'TXT'
Afmetingen werk
Ruimte 1 = 47 m²
Ruimte 2A = 56 m²
Ruimte 2B = 35 m²
Ruimte 3 = 27 m²
Totaal netto = 165 m²
Bruto 174 m²
Snijverlies 6%
TXT;

        $parser = new ScannedDimensionsParser;
        $this->assertTrue($parser->matches($text));
        $parsed = $parser->parse($text);

        $this->assertCount(4, $parsed['rooms']);
        $this->assertSame(165.0, $parsed['netto_total']);
        $this->assertSame(174.0, $parsed['bruto_total']);
        $this->assertSame(6.0, $parsed['snijverlies_pct']);

        $byNumber = collect($parsed['rooms'])->keyBy('room_number');
        $this->assertSame(47.0, $byNumber['1']['quantity']);
        $this->assertSame(56.0, $byNumber['2A']['quantity']);
        $this->assertSame(35.0, $byNumber['2B']['quantity']);
        $this->assertSame(27.0, $byNumber['3']['quantity']);
        $this->assertSame('ok', $byNumber['1']['status']);
        $this->assertSame(
            165.0,
            round($byNumber['1']['quantity'] + $byNumber['2A']['quantity'] + $byNumber['2B']['quantity'] + $byNumber['3']['quantity'], 2)
        );
    }

    public function test_parses_handwritten_netto_on_separate_lines(): void
    {
        $text = <<<'TXT'
Ruimte 1
netto 47 m²
bruto 52 m²
baanlengte 12 m
Ruimte 2A
netto 56 m²
Ruimte 2B
netto 35 m²
Ruimte 3
netto 27 m²
Totaal netto = 165 m²
Snijverlies 6%
TXT;

        $parsed = (new ScannedDimensionsParser)->parse($text);
        $byNumber = collect($parsed['rooms'])->keyBy('room_number');

        $this->assertCount(4, $parsed['rooms']);
        $this->assertSame(165.0, $parsed['netto_total']);
        $this->assertSame(47.0, $byNumber['1']['quantity']);
        $this->assertSame(52.0, $byNumber['1']['bruto']);
        $this->assertSame(56.0, $byNumber['2A']['quantity']);
        $this->assertSame(35.0, $byNumber['2B']['quantity']);
        $this->assertSame(27.0, $byNumber['3']['quantity']);
        $this->assertSame(4, collect($parsed['rooms'])->where('quantity', '>', 0)->count());
    }

    public function test_marks_incomplete_rooms_as_controleren_instead_of_dropping_them(): void
    {
        $text = <<<'TXT'
Ruimte 1
netto 47 m²
Ruimte 2A
Ruimte 2B
netto 35 m²
Ruimte 3
TXT;

        $parsed = (new ScannedDimensionsParser)->parse($text);
        $byNumber = collect($parsed['rooms'])->keyBy('room_number');

        $this->assertCount(4, $parsed['rooms']);
        $this->assertSame(47.0, $byNumber['1']['quantity']);
        $this->assertSame('ok', $byNumber['1']['status']);
        $this->assertSame(0.0, $byNumber['2A']['quantity']);
        $this->assertSame('controleren', $byNumber['2A']['status']);
        $this->assertSame(35.0, $byNumber['2B']['quantity']);
        $this->assertSame('controleren', $byNumber['3']['status']);
        $this->assertSame(82.0, $parsed['netto_total']);
    }

    public function test_does_not_turn_bruto_or_snijverlies_into_floor_square_meters(): void
    {
        $text = <<<'TXT'
Ruimte 1
netto 47 m²
bruto 52 m²
baanlengte 18 m
Snijverlies 6%
Bruto 52 m²
TXT;

        $parsed = (new ScannedDimensionsParser)->parse($text);

        $this->assertCount(1, $parsed['rooms']);
        $this->assertSame(47.0, $parsed['rooms'][0]['quantity']);
        $this->assertSame(47.0, $parsed['netto_total']);
        $this->assertNotSame(52.0, $parsed['netto_total']);
        $this->assertNotSame(18.0, $parsed['netto_total']);
    }

    public function test_does_not_match_nicon_meetbon(): void
    {
        $text = "Meetstaat\nBouwlaag: begane grond\nOpdrachtgever: Test\nWerknr: 1\n0.07 groepsruimte 50,97 m²\n";

        $this->assertFalse((new ScannedDimensionsParser)->matches($text));
    }
}
