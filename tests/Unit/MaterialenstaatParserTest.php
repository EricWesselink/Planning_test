<?php

namespace Tests\Unit;

use App\Services\Meetstaat\MaterialenstaatParser;
use App\Services\Meetstaat\PdfTextExtractor;
use Tests\TestCase;

class MaterialenstaatParserTest extends TestCase
{
    public function test_reads_product_totals_without_rooms(): void
    {
        $text = <<<'TXT'
Materialenstaat
Marmoleum Real, 3120 rosato, Linoleum
Netto : 2226.69 m²
Plinten wit
Bruto - Deur : 1120 m¹
TXT;

        $parsed = (new MaterialenstaatParser(new PdfTextExtractor))->parseText($text);

        $this->assertSame([], $parsed['areas']);
        $marmoleum = collect($parsed['works'])->first(fn (array $work) => str_contains($work['name'], 'Marmoleum Real'));
        $plinten = collect($parsed['works'])->first(fn (array $work) => str_contains($work['name'], 'Plinten'));
        $this->assertNotNull($marmoleum);
        $this->assertNotNull($plinten);
        $this->assertEqualsWithDelta(2226.69, (float) $marmoleum['declared_total'], 0.01);
        $this->assertSame('m2', $marmoleum['unit']);
        $this->assertEqualsWithDelta(1120.0, (float) $plinten['declared_total'], 0.01);
        $this->assertSame('m1', $plinten['unit']);
    }

    public function test_reads_inline_netto_quantities_from_material_list_rows(): void
    {
        $text = <<<'TXT'
Materialenstaat
Artikel                                                      Netto hoeveelheid   Bruto hoeveelheid
Tarkett safe.t Granit Dark Sand 0508, PVC Banen / Vinyl              823.98 m²           857.20 m²       4.03%
Desso desert AC89 9523, Tapijttegels                                 840.01 m²           882.03 m²
Tarkett vinyl iQ Natural-dark warm grey, PVC Banen / Vinyl         2229.41 m²          2400.70 m²
TXT;

        $parsed = (new MaterialenstaatParser(new PdfTextExtractor))->parseText($text);

        $this->assertSame([], $parsed['areas']);
        $this->assertCount(3, $parsed['works']);
        $dark = collect($parsed['works'])->first(fn (array $work) => str_contains($work['name'], 'Dark Sand'));
        $this->assertNotNull($dark);
        $this->assertEqualsWithDelta(823.98, (float) $dark['declared_total'], 0.01);
        $this->assertSame('m2', $dark['unit']);
    }

    public function test_reads_project_header_from_materialenstaat_as_exact_text(): void
    {
        $text = <<<'TXT'
Materialenstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P230988 TWC studentenhuisvesting Utrecht
Werknummer    : 250200015
Datum         : 07/09/2026
Marmoleum Real, 3120 rosato, Linoleum
Netto : 120,00 m²
TXT;

        $parsed = (new MaterialenstaatParser(new PdfTextExtractor))->parseText($text);

        $this->assertSame('Nicon vloeren', $parsed['header']['customer_name']);
        $this->assertSame('11P230988 TWC studentenhuisvesting Utrecht', $parsed['header']['reference']);
        $this->assertSame('11P230988 TWC studentenhuisvesting Utrecht', $parsed['header']['project_name']);
        $this->assertSame('250200015', $parsed['header']['project_number']);
        $this->assertIsString($parsed['header']['project_number']);
        $this->assertNotSame(250200015, $parsed['header']['project_number']);
        $this->assertSame('2026-09-07', $parsed['header']['date']);
        $this->assertCount(1, $parsed['works']);
    }

    public function test_reads_werknr_label_as_well_as_werknummer(): void
    {
        $text = <<<'TXT'
Materialenstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P230988 TWC studentenhuisvesting Utrecht
Werknr        : 250200015
Datum         : 07/09/2026
TXT;

        $parsed = (new MaterialenstaatParser(new PdfTextExtractor))->parseText($text);

        $this->assertSame('250200015', $parsed['header']['project_number']);
    }

    public function test_wrapped_flooring_variant_lines_stay_one_material_each(): void
    {
        $text = <<<'TXT'
Materialenstaat
V.02 Zomer Gerflor Mipolam affinity 4424
Smoked Opal, PVC Banen / Vinyl
Bouwlaag: begane grond
Netto : 708,36 m²
V.04 Zomer Gerflor Mipolam affinity 4424
Cloudy Night, PVC Banen / Vinyl
Bouwlaag: verdieping 1
Netto : 609,44 m²
TXT;

        $parsed = (new MaterialenstaatParser(new PdfTextExtractor))->parseText($text);
        $names = collect($parsed['works'])->pluck('name');
        $v02 = collect($parsed['works'])->first(fn (array $work) => str_starts_with((string) $work['name'], 'V.02'));
        $v04 = collect($parsed['works'])->first(fn (array $work) => str_starts_with((string) $work['name'], 'V.04'));

        $this->assertCount(2, $parsed['works']);
        $this->assertFalse($names->contains('Smoked Opal, PVC Banen / Vinyl'));
        $this->assertFalse($names->contains('Cloudy Night, PVC Banen / Vinyl'));
        $this->assertNotNull($v02);
        $this->assertNotNull($v04);
        $this->assertStringContainsString('Smoked Opal', (string) $v02['name']);
        $this->assertStringContainsString('PVC', (string) $v02['name']);
        $this->assertStringContainsString('Vinyl', (string) $v02['name']);
        $this->assertStringContainsString('Cloudy Night', (string) $v04['name']);
        $this->assertEqualsWithDelta(708.36, (float) $v02['declared_total'], 0.01);
        $this->assertEqualsWithDelta(609.44, (float) $v04['declared_total'], 0.01);
        $this->assertSame('m2', $v02['unit']);
        $this->assertSame('m2', $v04['unit']);
        $this->assertSame([], $parsed['areas']);
        $this->assertSame([], $parsed['floors']);
    }
}
