<?php

namespace Tests\Unit;

use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\SnijmatenParser;
use Tests\TestCase;

class SnijmatenParserTest extends TestCase
{
    public function test_reads_material_floor_and_room_from_snijmaten(): void
    {
        $text = <<<'TXT'
Snijmaten
Marmoleum Real, 3120 rosato, Linoleum
Bouwlaag: begane grond
0.07 groepsruimte 50,97 m2
0.08 berging 24,01 m2
TXT;

        $parsed = (new SnijmatenParser(new PdfTextExtractor))->parseText($text);

        $room = collect($parsed['areas'])->first(fn (array $area) => $area['room_number'] === '0.07');
        $this->assertNotNull($room);
        $this->assertSame('begane grond', $room['floor']);
        $this->assertSame('groepsruimte', $room['room_name']);
        $this->assertEqualsWithDelta(50.97, (float) $room['square_meters'], 0.001);
        $this->assertSame('snijmaten', $room['source']);
        $this->assertSame('Marmoleum Real, 3120 rosato, Linoleum', $room['tasks'][0]['work_name']);
        $this->assertEqualsWithDelta(50.97, (float) $room['tasks'][0]['quantity'], 0.001);
    }

    public function test_reads_named_floor_cut_lines_as_floor_room_material_without_using_cut_length_as_m2(): void
    {
        $text = <<<'TXT'
Snijmaten
Tarkett safe.t Granit Dark Sand 0508, PVC Banen
/ Vinyl 181472 white moss
Baan Nr Groep Ruimte
1 begane grond tekenen 8.45
2 begane grond tekenen 8.15
7 begane grond magazijn tekenen 8.10
9 begane grond tekenlokaal 6.60
Totaal 428.60
Tarkett vinyl iQ Natural-pink clay, PVC Banen /
-99 begane grond kunstplein 4.50
TXT;

        $parsed = (new SnijmatenParser(new PdfTextExtractor))->parseText($text);

        $this->assertCount(4, $parsed['areas']);
        $tekenen = collect($parsed['areas'])->first(fn (array $area) => $area['room_name'] === 'tekenen');
        $this->assertNotNull($tekenen);
        $this->assertSame('begane grond', $tekenen['floor']);
        $this->assertNull($tekenen['square_meters']);
        $this->assertStringContainsString('Dark Sand', $tekenen['tasks'][0]['work_name']);
        $kunstplein = collect($parsed['areas'])->first(fn (array $area) => $area['room_name'] === 'kunstplein');
        $this->assertNotNull($kunstplein);
        $this->assertStringContainsString('pink clay', $kunstplein['tasks'][0]['work_name']);
        $this->assertSame([], array_values(array_filter($parsed['floors'], fn ($floor) => preg_match('/\d{2,}/', $floor))));
    }

    public function test_merges_banen_vinyl_continuation_onto_warm_grey_product(): void
    {
        $text = <<<'TXT'
Snijmaten
Tarkett vinyl iQ Natural-dark warm grey, PVC
Banen / Vinyl 181472 white moss
-99 begane grond instructieruimte 8.15
-99 begane grond muziek 10.30
Tarkett vinyl iQ Natural-aqua blue, PVC Banen /
Vinyl 181472 white moss
-99 begane grond brugklasplein 4.50
TXT;

        $parsed = (new SnijmatenParser(new PdfTextExtractor))->parseText($text);

        $instructie = collect($parsed['areas'])->first(fn (array $area) => $area['room_name'] === 'instructieruimte');
        $this->assertNotNull($instructie);
        $this->assertStringContainsString('dark warm grey', $instructie['tasks'][0]['work_name']);
        $this->assertStringContainsString('white moss', $instructie['tasks'][0]['work_name']);
        $brug = collect($parsed['areas'])->first(fn (array $area) => $area['room_name'] === 'brugklasplein');
        $this->assertNotNull($brug);
        $this->assertStringContainsString('aqua blue', $brug['tasks'][0]['work_name']);
    }
}
