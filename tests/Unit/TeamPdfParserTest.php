<?php

namespace Tests\Unit;

use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\TeamPdfParser;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class TeamPdfParserTest extends TestCase
{
    public function test_reads_labeled_team_fields(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
Naam: Team Wespro
Type: ZZP
Personen: 3
Namen: Kees, Piet, Jan
Vakkennis: PVC, Vinyl
E-mail: wespro@example.nl
Telefoon: 06 12345678
Bedrijf: Wespro Vloeren
Adres: Kerkstraat 2
Postcode: 8011 AA
Plaats: Zwolle
TXT);

        $team = $parsed['team'];
        $this->assertNotNull($team);
        $this->assertSame('Team Wespro', $team['name']);
        $this->assertSame('zzp', $team['employment_type']);
        $this->assertSame(3, $team['people_count']);
        $this->assertSame(['pvc', 'vinyl'], $team['specialties']);
        $this->assertSame('Kees, Piet, Jan', $team['crew_names']);
        $this->assertSame('wespro@example.nl', $team['email']);
        $this->assertSame('06 12345678', $team['phone']);
        $this->assertSame('Wespro Vloeren', $team['company']);
        $this->assertSame('Kerkstraat 2', $team['address']);
        $this->assertSame('8011 AA', $team['postal_code']);
        $this->assertSame('Zwolle', $team['city']);
        $this->assertCount(1, $parsed['teams']);
    }

    public function test_reads_unlabeled_team_block(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
Team Wespro
ZZP
3 personen
PVC, Linoleum
TXT);

        $team = $parsed['team'];
        $this->assertNotNull($team);
        $this->assertSame('Team Wespro', $team['name']);
        $this->assertSame('zzp', $team['employment_type']);
        $this->assertSame(3, $team['people_count']);
        $this->assertSame(['pvc', 'linoleum'], $team['specialties']);
    }

    public function test_reads_multiple_teams(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
Team Wespro
Type: ZZP
Personen: 2
Vakkennis: PVC

Team Jansen
Type: Eigen medewerker
Personen: 1
Vakkennis: Linoleum
TXT);

        $this->assertCount(2, $parsed['teams']);
        $this->assertSame('Team Wespro', $parsed['teams'][0]['name']);
        $this->assertSame('zzp', $parsed['teams'][0]['employment_type']);
        $this->assertSame('Team Jansen', $parsed['teams'][1]['name']);
        $this->assertSame('eigen', $parsed['teams'][1]['employment_type']);
        $this->assertSame(['linoleum'], $parsed['teams'][1]['specialties']);
    }

    public function test_people_count_follows_named_crew(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
Naam: Team Wespro
Namen: Kees, Piet
TXT);

        $this->assertSame(2, $parsed['team']['people_count']);
        $this->assertSame('Kees, Piet', $parsed['team']['crew_names']);
    }

    public function test_reads_nicon_team_roster_table(): void
    {
        $parsed = $this->parser()->parseText(<<<'TXT'
Team Voornaam Medewerker Rol
Team 1 Nick N.D.N. Seine Voorman
Mahmoud M. Khairallah Sulaiman
Mohammed M. Albadan
Team 2 Peter P. Korteschiel Voorman
Alexandr A. Korchahin
Jose J.S.V. da Costa
Team 3 Arek A.S. Gorzynski Vakman / voorman
Sietse S.D. van Dijk
Mo ??
Team 4 Lukasz Ozimek, L Voorman / vakman
Team 5 Lukas L. Lindenholz Voorman / vakman
TXT);

        $this->assertCount(5, $parsed['teams']);
        $this->assertSame('Team 1', $parsed['team']['name']);
        $this->assertSame(3, $parsed['team']['people_count']);
        $this->assertSame('Nick, Mahmoud, Mohammed', $parsed['team']['crew_names']);
        $this->assertSame(['Nick', 'Mahmoud', 'Mohammed'], array_column($parsed['team']['crew_members'], 'name'));
        $this->assertSame([], $parsed['team']['specialties']);

        $this->assertSame('Team 2', $parsed['teams'][1]['name']);
        $this->assertSame(['Peter', 'Alexandr', 'Jose'], array_column($parsed['teams'][1]['crew_members'], 'name'));
        $this->assertSame(['Arek', 'Sietse', 'Mo'], array_column($parsed['teams'][2]['crew_members'], 'name'));
        $this->assertSame(['Lukasz'], array_column($parsed['teams'][3]['crew_members'], 'name'));
        $this->assertSame(['Lukas'], array_column($parsed['teams'][4]['crew_members'], 'name'));
    }

    public function test_roster_header_is_not_a_team(): void
    {
        $parsed = $this->parser()->parseText("Team Voornaam Medewerker Rol\nTeam 1 Nick N.D.N. Seine Voorman");

        $this->assertCount(1, $parsed['teams']);
        $this->assertSame('Team 1', $parsed['team']['name']);
        $this->assertSame(['Nick'], array_column($parsed['team']['crew_members'], 'name'));
    }

    public function test_returns_no_team_when_the_pdf_has_no_name(): void
    {
        $parsed = $this->parser()->parseText("PVC\nLinoleum\n3 personen");

        $this->assertNull($parsed['team']);
        $this->assertSame([], $parsed['teams']);
    }

    public function test_text_pdf_without_a_team_name_returns_no_team(): void
    {
        $path = SimplePdf::path("PVC\nLinoleum");

        $parsed = $this->parser()->parseFile($path);
        @unlink($path);

        $this->assertNull($parsed['team']);
        $this->assertSame([], $parsed['teams']);
    }

    public function test_reads_a_text_pdf_file(): void
    {
        $path = SimplePdf::path("Naam: Team Wespro\nType: ZZP\nPersonen: 2\nVakkennis: PVC");

        $parsed = $this->parser()->parseFile($path);
        @unlink($path);

        $this->assertSame('Team Wespro', $parsed['team']['name']);
        $this->assertSame('zzp', $parsed['team']['employment_type']);
        $this->assertSame(2, $parsed['team']['people_count']);
        $this->assertSame(['pvc'], $parsed['team']['specialties']);
    }

    public function test_empty_pdf_text_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('geen leesbare tekst');

        $path = SimplePdf::path('');
        try {
            $this->parser()->parseFile($path);
        } finally {
            @unlink($path);
        }
    }

    private function parser(): TeamPdfParser
    {
        return new TeamPdfParser(new PdfTextExtractor);
    }
}
