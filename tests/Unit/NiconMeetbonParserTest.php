<?php

namespace Tests\Unit;

use App\Services\Meetstaat\Formats\NiconMeetbonParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use Tests\TestCase;

class NiconMeetbonParserTest extends TestCase
{
    public function test_parses_laakse_tuinen_text_extract(): void
    {
        $text = file_get_contents(base_path('tests/fixtures/nicon-meetbon-laakse-tuinen.txt'));
        $this->assertNotFalse($text);

        $parsed = (new NiconMeetbonParser)->parse($text);

        $this->assertSame('Nicon vloeren', $parsed['header']['customer_name']);
        $this->assertSame('11P260141 Laakse Tuinen Amersfoort', $parsed['header']['reference']);
        $this->assertSame('11P260141 Laakse Tuinen Amersfoort', $parsed['header']['project_name']);
        $this->assertSame('260200090', $parsed['header']['project_number']);
        $this->assertSame('2026-09-02', $parsed['header']['date']);

        $names = collect($parsed['works'])->pluck('name');
        $this->assertTrue($names->contains(fn ($name) => str_contains($name, 'Marmoleum Real')));
        $this->assertTrue($names->contains('Plinten wit'));
        $this->assertTrue($names->contains(fn ($name) => str_contains($name, 'Marmoleum Sport')));
        $this->assertFalse($names->contains(fn ($name) => str_starts_with($name, 'Omtrek')));
        $this->assertTrue($names->contains(fn ($name) => str_contains($name, 'PU gietvloer')));
        $this->assertTrue($names->contains(fn ($name) => str_contains($name, 'Entreemat') || str_contains($name, 'Coral Welcome')));

        $this->assertFalse($names->contains(fn ($name) => (bool) preg_match('/^\d+[.\-]\d+/', $name)));

        $room = collect($parsed['areas'])->first(fn ($area) => $area['room_number'] === '0.07' && $area['floor'] === 'begane grond');
        $this->assertNotNull($room);
        $this->assertSame('groepsruimte', $room['room_name']);

        $berging = collect($parsed['areas'])->first(fn ($area) => $area['room_number'] === '0.08' && $area['floor'] === 'begane grond');
        $this->assertNotNull($berging);
        $this->assertSame('berging', $berging['room_name']);

        $admin = collect($parsed['areas'])->first(fn ($area) => $area['room_number'] === '0.19a');
        $this->assertNotNull($admin);
        $this->assertSame('administratie', $admin['room_name']);
        $marmoleum = collect($room['tasks'])->first(fn ($task) => str_contains($task['work_name'], 'Marmoleum Real'));
        $this->assertEqualsWithDelta(50.97, (float) $marmoleum['quantity'], 0.011);
        $this->assertNotEmpty(collect($room['tasks'])->first(fn ($task) => $task['work_name'] === 'Plinten wit'));

        $hal = collect($parsed['areas'])->filter(fn ($area) => ($area['room_number'] ?? '') === '0.17' && $area['floor'] === 'begane grond' && $area['room_name'] === 'hal');
        $this->assertCount(1, $hal);
        $halTask = collect($hal->first()['tasks'])->first(fn ($task) => str_contains($task['work_name'], 'Marmoleum Real'));
        $this->assertEqualsWithDelta(116.58, (float) $halTask['quantity'], 0.02);

        $real = collect($parsed['works'])->first(fn ($work) => str_contains($work['name'], 'Marmoleum Real'));
        $this->assertEqualsWithDelta(2226.69, (float) $real['declared_total'], 0.02);
        $this->assertEqualsWithDelta(2226.69, (float) $real['calculated_total'], 1.0);

        $plint = collect($parsed['works'])->first(fn ($work) => $work['name'] === 'Plinten wit');
        $this->assertGreaterThan(1100, (float) $plint['calculated_total']);
    }

    public function test_starts_work_when_product_is_followed_by_bouwlaag(): void
    {
        $text = <<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P260141 Laakse Tuinen Amersfoort
Werknr        : 260200090
Datum         : 02/09/2026

Marmoleum Real, 3120 rosato, Linoleum
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek - Deur Naden
0.07 groepsruimte 50.97 m² 25.31 m 20.97 m
0.09 groepsruimte 51.97 m² 14.27 m 22.44 m
Totaal 102.94 m² 39.58 m 43.41 m
Netto : 102.94 m²

Plint, wit, Plinten
Bouwlaag: begane grond
Ruimte Omtrek - Deur
0.07 groepsruimte 25.31 m
0.08berging 19.73 m
Totaal 45.04 m
Bruto - Deur : 45.04 m
TXT;

        $parsed = (new NiconMeetbonParser)->parse($text);
        $names = collect($parsed['works'])->pluck('name');

        $this->assertTrue($names->contains(fn ($name) => str_contains($name, 'Marmoleum Real')));
        $this->assertTrue($names->contains('Plinten wit'));
        $this->assertFalse($names->contains(fn ($name) => (bool) preg_match('/^\d+[.\-]\d+/', $name)));
        $this->assertFalse($names->contains('berging'));

        $room = collect($parsed['areas'])->first(fn ($area) => $area['room_number'] === '0.07');
        $this->assertNotNull($room);
        $this->assertNotEmpty(collect($room['tasks'])->first(fn ($task) => str_contains($task['work_name'], 'Marmoleum')));
        $this->assertNotEmpty(collect($room['tasks'])->first(fn ($task) => $task['work_name'] === 'Plinten wit'));
    }

    public function test_parser_matches_nicon_meetbon_format(): void
    {
        $parser = new NiconMeetbonParser;
        $this->assertTrue($parser->matches('Meetstaat Opdrachtgever Bouwlaag: begane grond Werknr'));
        $this->assertFalse($parser->matches('willekeurige excel dump'));
    }

    public function test_reads_pdf_without_filename_extension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        $this->assertNotFalse($path);
        copy(base_path('tests/fixtures/nicon-meetbon-laakse-tuinen.pdf'), $path);

        $parsed = (new MeetstaatReader(new PdfTextExtractor))->parseFile($path, 'Meetbon_Laakse_Tuinen.pdf');

        $this->assertSame('11P260141 Laakse Tuinen Amersfoort', $parsed['header']['project_name']);
        @unlink($path);
    }

    public function test_reads_pdf_from_magic_bytes_when_name_is_missing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        $this->assertNotFalse($path);
        copy(base_path('tests/fixtures/nicon-meetbon-laakse-tuinen.pdf'), $path);

        $parsed = (new MeetstaatReader(new PdfTextExtractor))->parseFile($path);

        $this->assertSame('11P260141 Laakse Tuinen Amersfoort', $parsed['header']['project_name']);
        @unlink($path);
    }

    public function test_reads_the_uploaded_pdf(): void
    {
        $path = base_path('tests/fixtures/nicon-meetbon-laakse-tuinen.pdf');
        $this->assertFileExists($path);

        $parsed = (new MeetstaatReader(new PdfTextExtractor))->parseFile($path);
        $this->assertFalse($parsed['needs_ocr']);
        $this->assertSame('11P260141 Laakse Tuinen Amersfoort', $parsed['header']['project_name']);
        $this->assertGreaterThanOrEqual(6, count($parsed['works']));
        $this->assertGreaterThan(50, count($parsed['areas']));
    }

    public function test_room_number_prefix_fills_missing_bouwlaag_without_changing_quantities(): void
    {
        $text = <<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : test
Werknr        : 250500046
Datum         : 07/09/2026

Lino Art Urban R893-0555, flashy street grey, Linoleum
03.08 douches 10.31 m² 12.00 m
03.13b verkeersruimte 25.31 m² 20.00 m
OAT container ruimte 28.96 m² 22.00 m
Totaal 64.58 m² 54.00 m
Netto : 64.58 m²
TXT;

        $parsed = (new NiconMeetbonParser)->parse($text);

        $douches = collect($parsed['areas'])->first(fn (array $area) => ($area['room_number'] ?? '') === '03.08');
        $verkeer = collect($parsed['areas'])->first(fn (array $area) => ($area['room_number'] ?? '') === '03.13b');
        $oat = collect($parsed['areas'])->first(fn (array $area) => ($area['room_name'] ?? '') === 'OAT container ruimte');

        $this->assertNotNull($douches);
        $this->assertNotNull($verkeer);
        $this->assertNotNull($oat);
        $this->assertSame('verdieping 3', $douches['floor']);
        $this->assertSame('verdieping 3', $verkeer['floor']);
        $this->assertSame('Onbekend', $oat['floor']);
        $this->assertEqualsWithDelta(10.31, (float) $douches['tasks'][0]['quantity'], 0.001);
        $this->assertEqualsWithDelta(25.31, (float) $verkeer['tasks'][0]['quantity'], 0.001);
        $this->assertEqualsWithDelta(28.96, (float) $oat['tasks'][0]['quantity'], 0.001);
    }

    public function test_wrapped_cork_product_header_stays_one_canonical_work(): void
    {
        $text = <<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : test
Werknr        : 250500046
Datum         : 07/09/2026

Lino Art Urban R893-0555 op kurk, flashy street
grey, Linoleum
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek
00.27 berging speellokaal 6.19 m² 12.46 m
00.28 speellokaal 84.22 m² 37.40 m
hal 29.98 m² 23.54 m
Totaal 120.39 m² 73.40 m
Totaal
120.39 m² 73.40 m
TXT;

        $parsed = (new NiconMeetbonParser)->parse($text);
        $names = collect($parsed['works'])->pluck('name');

        $this->assertTrue($names->contains(
            fn ($name) => str_contains((string) $name, 'op kurk') && str_contains((string) $name, 'R893-0555')
        ));
        $this->assertFalse($names->contains('grey, Linoleum'));

        $cork = collect($parsed['works'])->first(
            fn (array $work) => str_contains((string) $work['name'], 'op kurk')
        );
        $this->assertNotNull($cork);
        $this->assertEqualsWithDelta(120.39, (float) $cork['declared_total'], 0.01);
        $this->assertEqualsWithDelta(120.39, (float) $cork['calculated_total'], 0.01);
    }

    public function test_wrapped_flooring_variant_header_stays_one_work_per_code(): void
    {
        $text = <<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : test
Werknr        : 250500046
Datum         : 07/09/2026

V.02 Zomer Gerflor Mipolam affinity 4424
Smoked Opal, PVC Banen / Vinyl
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek
00.01 hal 100.00 m² 40.00 m
Totaal 100.00 m² 40.00 m
Netto : 708,36 m²

V.04 Zomer Gerflor Mipolam affinity 4424
Cloudy Night, PVC Banen / Vinyl
Bouwlaag: verdieping 1
Ruimte Oppervlakte Omtrek
01.02 kantoor 80.00 m² 36.00 m
Totaal 80.00 m² 36.00 m
Netto : 609,44 m²
TXT;

        $parsed = (new NiconMeetbonParser)->parse($text);
        $names = collect($parsed['works'])->pluck('name');
        $v02 = collect($parsed['works'])->first(fn (array $work) => str_contains((string) $work['name'], 'V.02'));
        $v04 = collect($parsed['works'])->first(fn (array $work) => str_contains((string) $work['name'], 'V.04'));
        $hal = collect($parsed['areas'])->first(fn (array $area) => ($area['room_number'] ?? '') === '00.01');
        $kantoor = collect($parsed['areas'])->first(fn (array $area) => ($area['room_number'] ?? '') === '01.02');

        $this->assertCount(2, $parsed['works']);
        $this->assertFalse($names->contains('Smoked Opal, PVC Banen / Vinyl'));
        $this->assertFalse($names->contains('Cloudy Night, PVC Banen / Vinyl'));
        $this->assertNotNull($v02);
        $this->assertNotNull($v04);
        $this->assertStringContainsString('Smoked Opal', (string) $v02['name']);
        $this->assertStringContainsString('Cloudy Night', (string) $v04['name']);
        $this->assertEqualsWithDelta(708.36, (float) $v02['declared_total'], 0.01);
        $this->assertEqualsWithDelta(609.44, (float) $v04['declared_total'], 0.01);
        $this->assertNotNull($hal);
        $this->assertNotNull($kantoor);
        $this->assertSame('begane grond', $hal['floor']);
        $this->assertSame('verdieping 1', $kantoor['floor']);
        $this->assertSame('hal', $hal['room_name']);
        $this->assertSame('kantoor', $kantoor['room_name']);
        $this->assertEqualsWithDelta(100.0, (float) $hal['tasks'][0]['quantity'], 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $kantoor['tasks'][0]['quantity'], 0.001);
        $this->assertStringContainsString('V.02', (string) $hal['tasks'][0]['work_name']);
        $this->assertStringContainsString('V.04', (string) $kantoor['tasks'][0]['work_name']);
    }
}
