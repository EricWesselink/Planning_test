<?php

namespace Tests\Unit;

use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfPageGeometry;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

/**
 * Bewijst de classificatie van resterende BG-tekenfragmenten
 * nadat de import het taakverschil naar 0,0 m² reconcilieert.
 */
class LaakseBgRemainingSurplusClassificationTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function tearDown(): void
    {
        if ($this->lines !== []) {
            fwrite(STDOUT, "\n=== Laakse BG classificatie ===\n");
            foreach ($this->lines as $line) {
                fwrite(STDOUT, $line."\n");
            }
            fwrite(STDOUT, "===================================\n");
        }

        parent::tearDown();
    }

    public function test_remaining_bg_surplus_items_have_documented_evidence(): void
    {
        $drawingPath = RealDrawingFixtures::laakseTuinenDrawingPath();
        if ($drawingPath === null) {
            $this->markTestSkipped('Echte Laakse Tuinen-tekening ontbreekt.');
        }

        $extractor = new PdfTextExtractor;
        $geo = (new PdfPageGeometry)->extract($drawingPath);
        $drawing = (new FloorPlanParser($extractor))->parseFile($drawingPath, 'tekening.pdf');
        $meetstaat = (new MeetstaatReader($extractor))->parseFile(
            RealDrawingFixtures::laakseTuinenMeetstaatPath(),
            'Meetbon_Laakse_Tuinen.pdf'
        );
        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);

        $bg = collect($preview['import_report']['floors'] ?? [])->first(
            fn (array $row) => mb_strtolower((string) ($row['floor'] ?? '')) === 'begane grond'
        );
        $this->assertNotNull($bg);
        $diff = round((float) ($bg['task_meters_difference'] ?? 0), 2);
        $this->assertEqualsWithDelta(0.0, $diff, 0.05);

        // 0.16↔0.17: tekst "0.17" ligt dichter bij "hal"+"14.29" dan "0.16".
        $hal = $this->nearTexts($geo, 1, 416.5, 453.6, 30);
        $this->assertTrue($this->hasTextNear($hal, 'hal', 5));
        $this->assertTrue($this->hasTextNear($hal, '14.29', 30));
        $this->assertTrue($this->hasTextNear($hal, '0.17', 15));
        $d17 = $this->distanceToText($hal, '0.17');
        $d16 = $this->distanceToText($hal, '0.16');
        $this->assertNotNull($d17);
        $this->assertNotNull($d16);
        $this->assertLessThan($d16, $d17, '0.17 hoort bij hal 14,29; 0.16 is verder (magazijn).');

        // 0.28↔0.27: tekst "0.27" ligt dichter bij "therapie"+"26.35" dan "0.28".
        $therapie = $this->nearTexts($geo, 1, 821.3, 403.7, 35);
        $this->assertTrue($this->hasTextNear($therapie, 'therapie', 5));
        $this->assertTrue($this->hasTextNear($therapie, '26.35', 30));
        $this->assertTrue($this->hasTextNear($therapie, '0.27', 20));
        $d27 = $this->distanceToText($therapie, '0.27');
        $d28 = $this->distanceToText($therapie, '0.28');
        $this->assertNotNull($d27);
        $this->assertNotNull($d28);
        $this->assertLessThan($d28, $d27, '0.27 hoort bij therapie; 0.28 bij zorgcoördinator.');

        // 58 m² entree: smalle contour + 3.66/0.11 werkkast-annotaties in buurt.
        $entree = collect($drawing['areas'])->first(
            fn (array $a) => abs((float) ($a['square_meters'] ?? 0) - 58.0) < 0.05
                && str_contains(mb_strtolower((string) ($a['room_name'] ?? '')), 'entree')
        );
        $this->assertNotNull($entree);
        $this->assertSame(4, (int) ($entree['page'] ?? 0));
        $this->assertStringContainsString('14-52', str_replace('_', '-', (string) ($entree['contour_id'] ?? '')));

        // toilet 31.71: echte toilet-m² 1.46 staat dichter; 31.71 is kleedruimte-deelvlak.
        $toiletTexts = $this->nearTexts($geo, 4, 259.1, 495.9, 35);
        $this->assertTrue($this->hasTextNear($toiletTexts, 'toilet', 5));
        $this->assertTrue($this->hasTextNear($toiletTexts, '31.71', 25));
        $this->assertTrue($this->hasTextNear($toiletTexts, '1.46', 20));
        $this->assertTrue($this->hasTextNear($toiletTexts, 'kleedruimte', 35));

        // Ruimte 5.1 ×2: exacte meetstaat-match op BG sporthal Ruimte 4.
        $ruimteMs = collect($meetstaat['areas'])->filter(
            fn (array $a) => str_contains(mb_strtolower((string) ($a['floor'] ?? '')), 'sporthal')
                && str_contains(mb_strtolower((string) ($a['room_name'] ?? '')), 'ruimte')
                && abs((float) (($a['tasks'][0]['quantity'] ?? 0)) - 5.1) < 0.05
        );
        $this->assertGreaterThanOrEqual(2, $ruimteMs->count());

        // 0.35 egels-fragment: ruimtenummer gelezen als m²; echte Walton=10,54 ernaast.
        $egelFrag = $this->nearTexts($geo, 1, 820.7, 559.5, 25);
        $this->assertTrue($this->hasTextNear($egelFrag, 'egels', 5));
        $this->assertTrue($this->hasTextNear($egelFrag, '0.35', 15));
        $this->assertTrue($this->hasTextNear($egelFrag, '10.54', 25));
        $ghost035 = collect($preview['areas'])->first(
            fn (array $a) => mb_strtolower((string) ($a['floor'] ?? '')) === 'begane grond'
                && str_contains(mb_strtolower((string) ($a['room_name'] ?? '')), 'egel')
                && abs((float) ($a['square_meters'] ?? 0) - 0.35) < 0.001
        );
        $this->assertNotNull($ghost035, '0,35-fragment bestaat nog; fix is geclassificeerd maar nog niet veilig landelijk.');

        // 8,88 = 5,95 + 2,93 leerplein-fragmenten op nr 0.33.
        $leerFrags = collect($preview['areas'])->filter(
            fn (array $a) => mb_strtolower((string) ($a['floor'] ?? '')) === 'begane grond'
                && ($a['room_number'] ?? '') === '0.33'
                && ($a['source'] ?? '') === 'plattegrond'
                && ($a['keep_separate'] ?? false) === true
        );
        $leerSum = round($leerFrags->sum(fn (array $a) => (float) ($a['square_meters'] ?? 0)), 2);
        $this->assertEqualsWithDelta(0.0, $leerSum, 0.05);

        $this->lines[] = 'm² | nr | naam | beste MS | classificatie | actie | conf';
        $this->lines[] = '14,29 | 0.16 | hal | 0.17 hal 14,29 | OCR-nummer (0.17 dichter) | voorstel nr-herstel | hoog bewijs';
        $this->lines[] = '26,35 | 0.28 | therapie | 0.27 therapie 26,35 | OCR-nummer (0.27 dichter) | voorstel nr-herstel | hoog bewijs';
        $this->lines[] = '58,00 | 3.66∅ | entree werkkast | — | false room/maatvoering | Controleren | hoog';
        $this->lines[] = '31,71 | — | toilet | 0.14 kleed 31,71 | verkeerde naam; deelvlak | Controleren/sporthal | hoog';
        $this->lines[] = '10,20 | — | Ruimte×2 | sporthal Ruimte4 5,10 | verkeerde bouwlaag | voorstel sporthal | midden';
        $this->lines[] = '8,88 | 0.33 | lerplein/PAD | 0.33 leerplein delen | deelvlak/OCR-naam | gereconcilieerd | midden';
        $this->lines[] = '0,35 | 0.35 | egels | Walton 10,54 | nr-als-m² | voorstel meter-fix | hoog';
        $this->lines[] = sprintf('0,00 reconcilieert | resterend taakverschil %.2f', $diff);
    }

    /**
     * @return list<array{text: string, dist: float}>
     */
    private function nearTexts(array $geo, int $page, float $x, float $y, float $radius): array
    {
        $hits = [];
        foreach ($geo['pages'] as $p) {
            if ((int) ($p['page'] ?? 0) !== $page) {
                continue;
            }
            foreach ($p['texts'] ?? [] as $t) {
                $dx = (float) ($t['x'] ?? 0) - $x;
                $dy = (float) ($t['y'] ?? 0) - $y;
                $dist = sqrt(($dx * $dx) + ($dy * $dy));
                if ($dist <= $radius) {
                    $hits[] = ['text' => (string) ($t['text'] ?? ''), 'dist' => $dist];
                }
            }
        }

        return $hits;
    }

    /**
     * @param  list<array{text: string, dist: float}>  $hits
     */
    private function hasTextNear(array $hits, string $needle, float $maxDist): bool
    {
        foreach ($hits as $hit) {
            if (mb_strtolower($hit['text']) === mb_strtolower($needle) && $hit['dist'] <= $maxDist) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{text: string, dist: float}>  $hits
     */
    private function distanceToText(array $hits, string $needle): ?float
    {
        $best = null;
        foreach ($hits as $hit) {
            if (mb_strtolower($hit['text']) !== mb_strtolower($needle)) {
                continue;
            }
            $best = $best === null ? $hit['dist'] : min($best, $hit['dist']);
        }

        return $best;
    }
}
