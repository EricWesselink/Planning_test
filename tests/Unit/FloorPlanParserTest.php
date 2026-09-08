<?php

namespace Tests\Unit;

use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\PdfTextExtractor;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

class FloorPlanParserTest extends TestCase
{
    public function test_reads_room_number_name_and_square_meters_from_the_same_line(): void
    {
        $text = <<<'TXT'
begane grond
0.07 groepsruimte 50,97 m2
0.08 berging 24,01 m2
1.19 groepsruimte 40,00 m2
1.19a berging 8,00 m2
TXT;

        $parsed = (new FloorPlanParser(new PdfTextExtractor))->parseText($text, 'Plattegrond_BG.pdf');

        $this->assertSame('begane grond', $parsed['areas'][0]['floor']);
        $room = collect($parsed['areas'])->first(fn (array $area) => $area['room_number'] === '0.07');
        $this->assertNotNull($room);
        $this->assertSame('groepsruimte', $room['room_name']);
        $this->assertEqualsWithDelta(50.97, (float) $room['square_meters'], 0.001);
        $this->assertFalse($room['needs_review']);

        $suffix = collect($parsed['areas'])->first(fn (array $area) => $area['room_number'] === '1.19a');
        $plain = collect($parsed['areas'])->first(fn (array $area) => $area['room_number'] === '1.19');
        $this->assertNotNull($suffix);
        $this->assertNotNull($plain);
        $this->assertNotSame($suffix['key'], $plain['key']);
    }

    public function test_links_name_and_square_meters_on_following_lines(): void
    {
        $text = <<<'TXT'
verdieping 1
1.19
Groepsruimte
40,00 m2
TXT;

        $parsed = (new FloorPlanParser(new PdfTextExtractor))->parseText($text);

        $room = collect($parsed['areas'])->first(fn (array $area) => $area['room_number'] === '1.19');
        $this->assertNotNull($room);
        $this->assertSame('verdieping 1', $room['floor']);
        $this->assertSame('Groepsruimte', $room['room_name']);
        $this->assertEqualsWithDelta(40.0, (float) $room['square_meters'], 0.001);
    }

    public function test_keeps_a_named_room_when_square_meters_are_missing(): void
    {
        $text = "begane grond\n0.09 groepsruimte\n";

        $parsed = (new FloorPlanParser(new PdfTextExtractor))->parseText($text);

        $room = collect($parsed['areas'])->first(fn (array $area) => $area['room_number'] === '0.09');
        $this->assertNotNull($room);
        $this->assertNull($room['square_meters']);
        $this->assertTrue($room['needs_review']);
    }

    public function test_does_not_treat_a_bare_area_value_as_a_room_number(): void
    {
        $parsed = (new FloorPlanParser(new PdfTextExtractor))->parseText("50.97 m2\n");

        $this->assertSame([], $parsed['areas']);
    }

    public function test_does_not_treat_a_trailing_zero_area_value_as_a_room_number(): void
    {
        $parsed = (new FloorPlanParser(new PdfTextExtractor))->parseText("90.20 m2\n");

        $this->assertSame([], $parsed['areas']);
    }

    public function test_does_not_invent_floors_or_room_numbers_from_dimension_digits(): void
    {
        $text = <<<'TXT'
Tekening : begane grond (2/5)
1183
685
384
90.20 m2
verdieping 10
verdieping 99
TXT;

        $parsed = (new FloorPlanParser(new PdfTextExtractor))->parseText($text);

        $this->assertSame([], $parsed['areas']);
    }

    public function test_reads_known_rooms_from_the_real_griftland_drawing(): void
    {
        $path = $this->griftlandDrawingPath();
        if ($path === null) {
            $this->markTestSkipped('Echte Griftland-plattegrond ontbreekt.');
        }

        $parsed = (new FloorPlanParser(new PdfTextExtractor))->parseFile($path, 'Plattegrond.pdf');

        foreach ($parsed['areas'] as $area) {
            $this->assertContains($area['floor'], ['begane grond', 'verdieping 1', 'verdieping 2', 'installatie ruimten', 'Onbekend']);
            $this->assertNotContains((string) ($area['room_number'] ?? ''), ['1183', '685', '384', '232', '398', '956', '815']);
            $this->assertDoesNotMatchRegularExpression('/tarkett|vinyl iQ/i', (string) ($area['room_name'] ?? ''));
        }

        $this->assertHasNamedAreaContaining($parsed, 'begane grond', 'tekenlokaal', 90.20);
        $this->assertHasNamedArea($parsed, 'begane grond', 'tekenen', 91.84);
        $this->assertHasNamedArea($parsed, 'begane grond', 'handvaardigheid', 109.04);
        $this->assertHasNamedArea($parsed, 'begane grond', 'magazijn tekenen', 38.19);
        $this->assertHasNamedArea($parsed, 'begane grond', 'werkplaats td', 57.23);
        $this->assertHasNamedArea($parsed, 'begane grond', 'muziek', 108.06);
        $this->assertHasNamedArea($parsed, 'begane grond', 'toneel', 76.18);
        $this->assertHasNamedAreaContaining($parsed, 'verdieping 1', 'instructie', 60.08);
        $this->assertHasNamedArea($parsed, 'verdieping 1', 'practicum binask', 89.71);
        $this->assertHasNamedArea($parsed, 'verdieping 1', 'practicum binask', 90.47);
        $this->assertHasNamedArea($parsed, 'verdieping 1', 'toa kabinet', 52.98);
        $this->assertHasNamedArea($parsed, 'verdieping 1', 'science practicum', 104.04);
        $this->assertHasNamedArea($parsed, 'verdieping 1', 'betalab', 184.16);

        $kunstpleinen = collect($parsed['areas'])
            ->filter(fn (array $area) => mb_strtolower((string) ($area['floor'] ?? '')) === 'begane grond'
                && str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), 'kunstplein'))
            ->values();
        $this->assertGreaterThanOrEqual(2, $kunstpleinen->count());
        $this->assertNotNull($kunstpleinen->first(fn (array $area) => abs((float) $area['square_meters'] - 51.96) < 0.08));
        $this->assertNotNull($kunstpleinen->first(fn (array $area) => abs((float) $area['square_meters'] - 29.20) < 0.08));
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<float>  $meters
     */
    private function assertHasMeters(array $parsed, string $floor, array $meters): void
    {
        foreach ($meters as $value) {
            $hit = collect($parsed['areas'])->first(function (array $area) use ($floor, $value) {
                return mb_strtolower((string) ($area['floor'] ?? '')) === $floor
                    && abs((float) ($area['square_meters'] ?? 0) - $value) < 0.08
                    && trim((string) ($area['room_name'] ?? '')) !== '';
            });
            $this->assertNotNull($hit, $value.' m² op '.$floor.' ontbreekt.');
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function assertHasNamedAreaContaining(array $parsed, string $floor, string $needle, float $meters): void
    {
        $hit = collect($parsed['areas'])->first(function (array $area) use ($floor, $needle, $meters) {
            return mb_strtolower((string) ($area['floor'] ?? '')) === $floor
                && str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), mb_strtolower($needle))
                && abs((float) ($area['square_meters'] ?? 0) - $meters) < 0.08;
        });

        $this->assertNotNull($hit, $needle.' '.$meters.' m² op '.$floor.' ontbreekt. Gevonden: '.$this->areaSamples($parsed, $floor, $meters));
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function areaSamples(array $parsed, string $floor, float $meters): string
    {
        return collect($parsed['areas'])
            ->filter(fn (array $area) => mb_strtolower((string) ($area['floor'] ?? '')) === $floor)
            ->filter(fn (array $area) => abs((float) ($area['square_meters'] ?? 0) - $meters) < 5)
            ->map(fn (array $area) => ($area['room_name'] ?? '').' '.($area['square_meters'] ?? ''))
            ->take(8)
            ->implode(', ') ?: 'geen vergelijkbare m²';
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function assertHasNamedArea(array $parsed, string $floor, string $name, float $meters): void
    {
        $hit = collect($parsed['areas'])->first(function (array $area) use ($floor, $name, $meters) {
            return mb_strtolower((string) ($area['floor'] ?? '')) === $floor
                && mb_strtolower((string) ($area['room_name'] ?? '')) === mb_strtolower($name)
                && abs((float) ($area['square_meters'] ?? 0) - $meters) < 0.08;
        });

        $this->assertNotNull($hit, $name.' '.$meters.' m² op '.$floor.' ontbreekt.');
    }

    private function griftlandDrawingPath(): ?string
    {
        return RealDrawingFixtures::griftlandDrawingPath();
    }
}
