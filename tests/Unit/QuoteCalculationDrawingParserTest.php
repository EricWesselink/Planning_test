<?php

namespace Tests\Unit;

use App\Enums\FinishRole;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Services\QuoteCalculation\DrawingTakeoffParser;
use Tests\Support\RealDrawingFixtures;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class QuoteCalculationDrawingParserTest extends TestCase
{
    public function test_links_room_area_finish_code_and_legend_product(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
01.12 Woonkamer 24,5 m2 v01.g pl01
01.13 Sportzaal 120 m2 v02
v01.g = Marmoleum - Forbo 3752
v02 = Marmoleum - sportvloer
pl01 = Aluminium plakplint
TXT);

        $woonkamer = $this->line($parsed, '01.12', 'v01.g');
        $this->assertSame('Woonkamer', $woonkamer['room_name']);
        $this->assertEqualsWithDelta(24.5, (float) $woonkamer['quantity'], 0.001);
        $this->assertSame(WorkUnit::SquareMeter, $woonkamer['unit']);
        $this->assertSame('Marmoleum - Forbo 3752', $woonkamer['product']);
        $this->assertSame(QuantitySource::FromDrawing, $woonkamer['source']);

        $plint = $this->line($parsed, '01.12', 'pl01');
        $this->assertSame('Aluminium plakplint', $plint['product']);
        $this->assertSame(WorkUnit::LinearMeter, $plint['unit']);
        $this->assertSame(QuantitySource::Calculated, $plint['source']);
        $this->assertNotNull($plint['quantity']);
        $this->assertStringContainsString('"status":"estimated"', (string) ($plint['calculation_trace'] ?? ''));
    }

    public function test_does_not_add_unrelated_square_meters_to_a_room(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
01.12 Woonkamer 24,5 m2 v01.g
Totaal verdieping 450 m2
v01.g = Marmoleum - Forbo 3752
TXT);

        $quantities = collect($parsed['lines'])
            ->where('unit', WorkUnit::SquareMeter)
            ->pluck('quantity')
            ->all();

        $this->assertSame([24.5], array_map(fn ($value) => (float) $value, $quantities));
        $this->assertCount(1, collect($parsed['lines'])->where('room_number', '01.12'));
    }

    public function test_keeps_two_floor_finishes_in_one_room_with_their_own_areas(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
A-00-01 RECREATIE 78,90 m2 v01.d
v09 4,20 m2
v01.d = Marmoleum - Forbo 3732-3725
v09 = Schoonloopmat
TXT);

        $main = $this->line($parsed, 'A-00-01', 'v01.d');
        $local = $this->line($parsed, 'A-00-01', 'v09');

        $this->assertSame('RECREATIE', $main['room_name']);
        $this->assertSame(WorkUnit::SquareMeter, $main['unit']);
        $this->assertSame(WorkUnit::SquareMeter, $local['unit']);
        $this->assertSame('main', $main['finish_role']);
        $this->assertSame('local', $local['finish_role']);
        $this->assertEqualsWithDelta(78.9, (float) $main['room_area'], 0.001);
        $this->assertEqualsWithDelta(4.2, (float) $local['quantity'], 0.001);
        $this->assertEqualsWithDelta(74.7, (float) $main['quantity'], 0.001);
        $this->assertLessThan((float) $main['room_area'], (float) $local['quantity']);
    }

    public function test_does_not_give_a_second_floor_code_the_full_room_area(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
A-00-01 RECREATIE 78,90 m2 v01.d v09
v01.d = Marmoleum - Forbo 3732-3725
v09 = Schoonloopmat
TXT);

        $local = $this->line($parsed, 'A-00-01', 'v09');
        $main = $this->line($parsed, 'A-00-01', 'v01.d');

        $this->assertNull($local['quantity']);
        $this->assertSame('local', $local['finish_role']);
        $this->assertEqualsWithDelta(78.9, (float) $main['quantity'], 0.001);
    }

    public function test_marks_calculated_plinth_length_as_calculated(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
01.12 Woonkamer 24,5 m2 4,50 x 5,00 m v01.g pl01
v01.g = Marmoleum - Forbo 3752
pl01 = Aluminium plakplint
TXT);

        $plint = $this->line($parsed, '01.12', 'pl01');
        $this->assertSame(QuantitySource::Calculated, $plint['source']);
        $this->assertEqualsWithDelta(19.0, (float) $plint['quantity'], 0.001);
        $this->assertSame(WorkUnit::LinearMeter, $plint['unit']);
        $this->assertStringContainsString('volledige omtrek', (string) ($plint['note'] ?? ''));
        $this->assertStringContainsString('"status":"generous"', (string) ($plint['calculation_trace'] ?? ''));
    }

    public function test_does_not_guess_a_finish_code_from_a_separate_dump(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
01.12 Woonkamer 24,5 m2
01.13 Hal 12,0 m2
v01.g
v02
v01.g = Marmoleum - Forbo 3752
v02 = Marmoleum - sportvloer
TXT);

        $woonkamer = collect($parsed['lines'])->first(fn (array $line) => $line['room_number'] === '01.12');
        $hal = collect($parsed['lines'])->first(fn (array $line) => $line['room_number'] === '01.13');

        $this->assertNull($woonkamer['product_code']);
        $this->assertNull($hal['product_code']);
        $this->assertSame(QuantitySource::Review, $woonkamer['source']);
        $this->assertSame(QuantitySource::Review, $hal['source']);
    }

    public function test_splits_adjacent_legend_columns_instead_of_swallowing_the_next_code(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
w01 = Scanbehang wit, RAL9001 v01.g = Marmoleum - Forbo 3752
w04 = Multiplex wandafwerking, akoestisch v02 = Marmoleum - sportvloer
TXT);

        $legend = collect($parsed['legend'])->keyBy('code');
        $this->assertSame('Marmoleum - Forbo 3752', $legend['v01.g']['product']);
        $this->assertSame('Marmoleum - sportvloer', $legend['v02']['product']);
        $this->assertSame('Scanbehang wit, RAL9001', $legend['w01']['product']);
    }

    public function test_reads_oisterwijk_style_rooms_and_renvooi_without_guessing_codes(): void
    {
        $text = file_get_contents(base_path('tests/fixtures/calculatie-oisterwijk.txt'));
        $parsed = $this->parser()->parseText((string) $text);

        $legend = collect($parsed['legend'])->keyBy('code');
        $this->assertSame('Marmoleum - Forbo 3752', $legend['v01.g']['product']);
        $this->assertSame('Aluminium plakplint', $legend['pl01']['product']);
        $this->assertSame('Holplint', $legend['pl02']['product']);

        $speel = collect($parsed['lines'])->first(fn (array $line) => $line['room_number'] === 'A-00-12');
        $this->assertNotNull($speel);
        $this->assertSame('SPEELLOKAAL', $speel['room_name']);
        $this->assertEqualsWithDelta(86.9, (float) $speel['quantity'], 0.001);
        $this->assertNull($speel['product_code']);
        $this->assertSame(QuantitySource::Review, $speel['source']);

        $example = collect($parsed['lines'])->first(fn (array $line) => $line['room_number'] === 'A-0-01');
        $this->assertNull($example);

        $total = collect($parsed['lines'])->sum(fn (array $line) => (float) ($line['quantity'] ?? 0));
        $this->assertLessThan(450, $total);
    }

    public function test_pairs_gietvloer_with_holplint_using_a_generous_area_estimate(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
A-00-13 MIVA T 3,70 m2 v04
v04 = Gietvloer v.z.v. matte coating
pl02 = Holplint
TXT);

        $floor = $this->line($parsed, 'A-00-13', 'v04');
        $this->assertSame('MIVA T', $floor['room_name']);
        $this->assertEqualsWithDelta(3.7, (float) $floor['quantity'], 0.001);
        $this->assertSame(WorkUnit::SquareMeter, $floor['unit']);
        $this->assertSame('Gietvloer v.z.v. matte coating', $floor['product']);

        $plinth = $this->line($parsed, 'A-00-13', 'pl02');
        $this->assertSame('Holplint', $plinth['product']);
        $this->assertSame(WorkUnit::LinearMeter, $plinth['unit']);
        $this->assertSame(QuantitySource::Calculated, $plinth['source']);
        $this->assertNotNull($plinth['quantity']);
        $this->assertGreaterThan(4 * sqrt(3.7), (float) $plinth['quantity']);
        $this->assertStringContainsString('"status":"estimated"', (string) ($plinth['calculation_trace'] ?? ''));
        $this->assertStringContainsString('ruime calculatieschatting', (string) ($plinth['note'] ?? ''));
    }

    public function test_keeps_a_drawn_plakplint_code_instead_of_forcing_holplint(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
A-00-15 Toilet 1,50 m2 v04 pl01
v04 = Gietvloer v.z.v. matte coating
pl01 = Aluminium plakplint
pl02 = Holplint
TXT);

        $plinth = $this->line($parsed, 'A-00-15', 'pl01');
        $this->assertSame('Aluminium plakplint', $plinth['product']);
        $this->assertNull($this->missingLine($parsed, 'A-00-15', 'pl02'));
    }

    public function test_reads_linked_codes_from_a_pdf_text_layer(): void
    {
        $path = SimplePdf::path(<<<'TXT'
01.12 Woonkamer 24,5 m2 v01.g
v01.g = Marmoleum - Forbo 3752
TXT);

        try {
            $parsed = $this->parser()->parseFile($path);
        } finally {
            @unlink($path);
        }

        $line = $this->line($parsed, '01.12', 'v01.g');
        $this->assertSame('Marmoleum - Forbo 3752', $line['product']);
        $this->assertEqualsWithDelta(24.5, (float) $line['quantity'], 0.001);
    }

    public function test_reads_rooms_and_renvooi_from_the_supplied_bouwtekening(): void
    {
        $path = RealDrawingFixtures::oisterwijkDrawingPath();
        if ($path === null) {
            $this->markTestSkipped('De aangeleverde bouwtekening ontbreekt.');
        }

        $parsed = $this->parser()->parseFile($path);

        $legend = collect($parsed['legend'])->keyBy('code');
        $this->assertStringContainsString('Forbo 3752', (string) ($legend['v01.g']['product'] ?? ''));
        $this->assertStringContainsString('Gietvloer', (string) ($legend['v04']['product'] ?? ''));
        $this->assertNotEmpty($legend['pl01']['product'] ?? null);

        $rooms = collect($parsed['lines'])->pluck('room_number')->unique()->filter();
        $this->assertTrue($rooms->contains('A-00-12'));
        $this->assertTrue($rooms->contains('A-00-01'));

        $recreatie = collect($parsed['lines'])->first(
            fn (array $line) => $line['room_number'] === 'A-00-01'
                && $line['unit'] === WorkUnit::SquareMeter
                && ($line['finish_role'] ?? FinishRole::Main->value) !== FinishRole::Local->value
        );
        $this->assertNotNull($recreatie);
        $this->assertSame('v01.d', $recreatie['product_code']);
        $this->assertStringContainsStringIgnoringCase('marmoleum', (string) $recreatie['product']);
        $this->assertEqualsWithDelta(78.9, (float) $recreatie['quantity'], 0.1);

        $localMat = collect($parsed['lines'])->first(
            fn (array $line) => $line['room_number'] === 'A-00-01'
                && $line['unit'] === WorkUnit::SquareMeter
                && mb_strtolower((string) $line['product_code']) === 'v09'
        );
        $this->assertNotNull($localMat);
        $this->assertSame(FinishRole::Local->value, $localMat['finish_role'] ?? FinishRole::Local->value);
        $this->assertNotEquals(78.9, (float) ($localMat['quantity'] ?? 0));

        $speel = collect($parsed['lines'])->first(
            fn (array $line) => $line['room_number'] === 'A-00-12' && $line['unit'] === WorkUnit::SquareMeter
        );
        $this->assertNotNull($speel);
        $this->assertSame('v02', $speel['product_code']);
        $this->assertStringContainsStringIgnoringCase('sportvloer', (string) $speel['product']);
        $this->assertEqualsWithDelta(86.9, (float) $speel['quantity'], 0.1);
        $this->assertSame(QuantitySource::FromDrawing, $speel['source']);

        $verkeer = collect($parsed['lines'])->first(
            fn (array $line) => $line['room_number'] === 'A-00-06' && $line['unit'] === WorkUnit::SquareMeter
        );
        $this->assertNotNull($verkeer);
        $this->assertSame('VERKEER, SPEEL, BEWEGING', $verkeer['room_name']);
        $this->assertNotEmpty($verkeer['product_code']);

        $this->assertFalse(collect($parsed['warnings'])->contains(
            fn (string $warning) => str_contains($warning, 'Geen betrouwbare ruimtes gekoppeld')
        ));

        $floors = collect($parsed['lines'])->where('unit', WorkUnit::SquareMeter);
        $this->assertTrue($floors->every(fn (array $line) => str_starts_with((string) $line['room_number'], 'A-00-')));
        $this->assertCount(21, $floors->pluck('room_number')->unique()->filter());
        $linked = $floors->filter(fn (array $line) => filled($line['product_code']))->count();
        $this->assertGreaterThanOrEqual(18, $linked);
    }

    public function test_links_floor_codes_across_all_supplied_coa_drawings_without_guessing(): void
    {
        $paths = RealDrawingFixtures::coaOisterwijkDrawingPaths();
        if (count($paths) < 7) {
            $this->markTestSkipped('De 7 COA-tekeningen ontbreken.');
        }

        $parser = $this->parser();
        $rooms = 0;
        $floors = 0;
        $plinths = 0;
        foreach ($paths as $path) {
            $parsed = $parser->parseFile($path);
            $this->assertFalse(collect($parsed['warnings'])->contains(
                fn (string $warning) => str_contains($warning, 'Geen betrouwbare ruimtes gekoppeld')
            ), basename($path));
            $floorLines = collect($parsed['lines'])->where('unit', WorkUnit::SquareMeter);
            $rooms += $floorLines->pluck('room_number')->unique()->filter()->count();
            $floors += $floorLines->filter(fn (array $line) => filled($line['product_code']) && filled($line['product']))->count();
            $plinths += collect($parsed['lines'])->where('unit', WorkUnit::LinearMeter)
                ->filter(fn (array $line) => filled($line['product_code']))
                ->count();
        }

        $this->assertSame(126, $rooms);
        $this->assertGreaterThanOrEqual(100, $floors);
        $this->assertGreaterThanOrEqual(90, $plinths);
    }

    /**
     * @param  array{lines: list<array<string, mixed>>}  $parsed
     * @return array<string, mixed>
     */
    private function line(array $parsed, string $room, string $code): array
    {
        $line = collect($parsed['lines'])->first(
            fn (array $row) => $row['room_number'] === $room && $row['product_code'] === $code
        );
        $this->assertNotNull($line);

        return $line;
    }

    /**
     * @param  array{lines: list<array<string, mixed>>}  $parsed
     */
    private function missingLine(array $parsed, string $room, string $code): mixed
    {
        return collect($parsed['lines'])->first(
            fn (array $row) => $row['room_number'] === $room && $row['product_code'] === $code
        );
    }

    private function parser(): DrawingTakeoffParser
    {
        return new DrawingTakeoffParser;
    }
}
