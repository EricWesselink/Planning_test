<?php

namespace Tests\Feature;

use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Support\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ScreenExcelImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_shows_manual_project_form_and_file_dropzone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('projects.create'))
            ->assertOk()
            ->assertSee('Handmatig project')
            ->assertSee('Bedrijf / opdrachtgever')
            ->assertSee('Excel raambekleding / zonwering')
            ->assertSee('Projectbestanden')
            ->assertSee('Sleep bestanden hierheen');
    }

    public function test_uitvoerder_is_forbidden_from_creating_a_screen_project(): void
    {
        $user = User::factory()->uitvoerder()->create();

        $this->actingAs($user)
            ->post(route('projects.store'), [
                'customer_name' => 'Zonwering BV',
                'name' => 'School screens',
                'excel' => $this->screenFile(),
            ])
            ->assertForbidden();

        $this->assertSame(0, Project::query()->count());
    }

    public function test_planner_creates_separate_screen_lines_and_skips_labor_hours(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'customer_name' => 'Zonwering BV',
            'name' => 'School screens',
            'address' => 'Schoolstraat 1',
            'postal_code' => '3811 AA',
            'city' => 'Amersfoort',
            'excel' => $this->screenFile(),
        ]);

        $project = Project::query()->where('name', 'School screens')->first();
        $this->assertNotNull($project);
        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('status', 'Project aangemaakt. 3 Excelregels verwerkt · 15 stuks · 0 niet herkend. Klaar voor planning.');

        $this->assertSame('Zonwering BV', $project->customer?->name);
        $this->assertSame('Schoolstraat 1', $project->address);
        $this->assertSame(1, $project->documents()->where('document_type', 'opdrachtlijst')->count());
        $this->assertSame(0, $project->documents()->where('document_type', 'meetstaat')->count());
        $this->assertSame(0, $project->workItems()->where('unit', WorkUnit::Hours)->count());
        $this->assertSame(0, $project->workItems()->where('unit', WorkUnit::SquareMeter)->count());

        $items = $project->workItems()->orderBy('sort_order')->get();
        $this->assertCount(3, $items);
        $this->assertSame('Screen H: 1700 mm B: 960 mm', $items[0]->name);
        $this->assertSame(12.0, (float) $items[0]->ordered_quantity);
        $this->assertSame(WorkUnit::Pieces, $items[0]->unit);
        $this->assertTrue(WorkType::isWindowCovering($items[0]->name));
        $this->assertSame('Screens', $items[0]->typeLabel());
        $this->assertFalse(WorkType::requiresPrimingLeveling($items[0]->name));

        $this->assertSame('BNR 11 Screen H: 1574 mm B: 770 mm', $items[1]->name);
        $this->assertSame(2.0, (float) $items[1]->ordered_quantity);
        $this->assertSame('BNR 12 Screen H: 1574 mm B: 770 mm', $items[2]->name);
        $this->assertSame(1.0, (float) $items[2]->ordered_quantity);

        $this->assertSame($items[0]->typeKey(), $items[1]->typeKey());
        $this->assertSame($items[0]->typeKey(), $items[2]->typeKey());

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Screen H: 1700 mm B: 960 mm')
            ->assertSee('BNR 11 Screen H: 1574 mm B: 770 mm')
            ->assertSee('12 st')
            ->assertDontSee('Primen & Egaliseren')
            ->assertDontSee('IMPORTCONTROLE');

        $this->actingAs($user)
            ->get(route('planning', ['project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Screens')
            ->assertSee('15 stuks');
    }

    public function test_screen_excel_dropzone_opens_simple_review_instead_of_floor_import(): void
    {
        $user = User::factory()->create();
        Cache::flush();

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [$this->screenFile()],
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/projecten/zonwering/', $response->headers->get('Location'));

        $this->actingAs($user)
            ->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Excel raambekleding / zonwering')
            ->assertSee('Screen H: 1700 mm B: 960 mm')
            ->assertSee('3 Excelregels verwerkt · 15 stuks · 0 niet herkend')
            ->assertDontSee('IMPORTCONTROLE')
            ->assertDontSee('Totaal verwacht');
    }

    public function test_floor_csv_dropzone_still_opens_meetstaat_review(): void
    {
        $user = User::factory()->create();

        $csv = UploadedFile::fake()->createWithContent('meetstaat.csv', implode("\n", [
            'nummer,naam,verdieping,m2,pvc',
            '01,Showroom,Begane grond,120,120',
        ])."\n");

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [$csv],
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/projecten/controle/', $response->headers->get('Location'));
    }

    public function test_existing_project_can_receive_a_screen_excel_upload(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('projects.store'), [
            'customer_name' => 'Zonwering BV',
            'name' => 'Leeg project',
            'city' => 'Amersfoort',
        ]);
        $project = Project::query()->where('name', 'Leeg project')->first();
        $this->assertNotNull($project);

        $this->actingAs($user)
            ->post(route('projects.screens.store', $project), [
                'excel' => $this->screenFile(),
            ])
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHas('status', '3 Excelregels verwerkt · 15 stuks · 0 niet herkend. Klaar voor planning.');

        $this->assertSame(3, WorkItem::query()->where('project_id', $project->id)->count());
    }

    public function test_screen_review_creates_the_project_without_floor_import(): void
    {
        $user = User::factory()->create();
        Cache::flush();

        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [$this->screenFile()],
        ]);
        $preview->assertRedirect();
        $token = basename((string) parse_url((string) $preview->headers->get('Location'), PHP_URL_PATH));

        $this->actingAs($user)->post(route('projects.screens.import', $token), [
            'customer_name' => 'Zonwering BV',
            'name' => 'Via dropzone',
            'city' => 'Amersfoort',
        ])->assertRedirect();

        $project = Project::query()->where('name', 'Via dropzone')->first();
        $this->assertNotNull($project);
        $this->assertSame(3, $project->workItems()->count());
        $this->assertSame(0, $project->documents()->where('document_type', 'meetstaat')->count());
        $this->assertSame(1, $project->documents()->where('document_type', 'opdrachtlijst')->count());
    }

    public function test_review_escapes_excel_descriptions(): void
    {
        $user = User::factory()->create();
        Cache::flush();

        $csv = UploadedFile::fake()->createWithContent('screens.csv', implode("\n", [
            'Productie Eenheid Omschrijving;Aantal;EH',
            '<script>alert(1)</script> Screen H: 1000 mm B: 800 mm;1;st',
        ])."\n");

        $response = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [$csv],
        ]);
        $response->assertRedirect();

        $html = $this->actingAs($user)->get($response->headers->get('Location'))->getContent();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_xlsx_with_a_tmp_upload_path_creates_screen_lines_instead_of_floor_rooms(): void
    {
        $user = User::factory()->create();
        $file = $this->screenXlsxAsTmpUpload();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'customer_name' => 'Koopmans',
            'name' => 'Zeewolde Bouw Havenkwartier Zuyd',
            'address' => 'Flaauwe werk 2',
            'postal_code' => '3894KW',
            'city' => 'Zeewolde',
            'excel' => $file,
        ]);

        $project = Project::query()->where('name', 'Zeewolde Bouw Havenkwartier Zuyd')->first();
        $this->assertNotNull($project);
        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('status', 'Project aangemaakt. 1 Excelregels verwerkt · 12 stuks · 0 niet herkend. Klaar voor planning.');

        $this->assertSame(1, $project->documents()->where('document_type', 'opdrachtlijst')->count());
        $this->assertSame(0, $project->documents()->where('document_type', 'meetstaat')->count());
        $this->assertSame(0, $project->workItems()->where('unit', WorkUnit::SquareMeter)->count());
        $this->assertSame('Screen H: 1700 mm B: 960 mm', $project->workItems()->first()?->name);
        $this->assertSame(12.0, (float) $project->workItems()->first()?->ordered_quantity);
        $this->assertLessThan(1000, (float) $project->areas()->max('square_meters'));
    }

    public function test_screen_calculation_excel_creates_piece_lines_instead_of_the_column_warning(): void
    {
        $user = User::factory()->create();
        $file = $this->screenCalculationXlsx();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'customer_name' => 'Koopmans',
            'name' => 'Zeewolde, Bouw Havenkwartier Zuyd',
            'address' => 'Flaauwe Werk 2',
            'postal_code' => '3894 KW',
            'city' => 'Zeewolde',
            'excel' => $file,
        ]);

        $project = Project::query()->where('name', 'Zeewolde, Bouw Havenkwartier Zuyd')->first();
        $this->assertNotNull($project);
        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('warnings', []);
        $response->assertSessionHas('status', fn ($status) => is_string($status) && str_contains($status, 'Excelregels verwerkt') && str_contains($status, 'stuks'));

        $this->assertSame(1, $project->documents()->where('document_type', 'opdrachtlijst')->count());
        $this->assertSame(0, $project->workItems()->where('unit', WorkUnit::SquareMeter)->count());
        $items = $project->workItems()->orderBy('sort_order')->get();
        $this->assertCount(2, $items);
        $this->assertSame('Screen H: 1700 mm B: 960 mm', $items[0]->name);
        $this->assertSame(12.0, (float) $items[0]->ordered_quantity);
        $this->assertSame('BNR 11 Screen H: 1574 mm B: 770 mm', $items[1]->name);
        $this->assertSame(2.0, (float) $items[1]->ordered_quantity);
    }

    public function test_does_not_crash_when_meetstaat_quantities_overflow_the_database_column(): void
    {
        $user = User::factory()->create();
        $csv = UploadedFile::fake()->createWithContent('meetstaat.csv', implode("\n", [
            'nummer,naam,verdieping,m2,pvc',
            '01,Hal,Begane grond,8414908006555,8414908006555',
        ])."\n");

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'customer_name' => 'Koopmans',
            'name' => 'Zeewolde',
            'city' => 'Zeewolde',
            'meetstaat' => $csv,
        ]);

        $project = Project::query()->where('name', 'Zeewolde')->first();
        $this->assertNotNull($project);
        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame(0, $project->areas()->count());
        $response->assertSessionHas('warnings');
    }

    private function screenFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('screens.csv', implode("\n", [
            'Groep;Productie Eenheid Omschrijving;Aantal;EH',
            'Screens;Screen H: 1700 mm B: 960 mm;12;st',
            'Screens;Screen H: 1700 mm B: 960 mm;4;uur',
            'BNR 11;BNR 11 Screen H: 1574 mm B: 770 mm;2;st',
            'BNR 11;BNR 11 Screen H: 1574 mm B: 770 mm;1;uur',
            'BNR 12;BNR 12 Screen H: 1574 mm B: 770 mm;1;st',
        ])."\n");
    }

    private function screenXlsxAsTmpUpload(): UploadedFile
    {
        $xlsx = $this->writeXlsx([
            ['Productie Eenheid Omschrijving', 'Aantal', 'EH'],
            ['Screen H: 1700 mm B: 960 mm', '12', 'st'],
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'php');
        copy($xlsx, $tmp);

        return new UploadedFile(
            $tmp,
            'screen.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function screenCalculationXlsx(): UploadedFile
    {
        $xlsx = $this->writeXlsx([
            ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs'],
            ['L', '4843-1', 'U', 'Screen H: 1700 mm B: 960 mm', 'Arbeid', '12', 'uur', '100'],
            ['O', '4843-1', 'O', 'Screen H: 1700 mm B: 960 mm', 'inkoop Suncircle', '12', 'st', '338'],
            ['O', '4843-1', 'O', 'BNR 11 Screen H: 1574 mm B: 770 mm', 'inkoop Suncircle', '2', 'st', '200'],
            ['M', '4843-1', 'M', 'Kitwerk', 'kit', '3', 'm2', '9'],
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'php');
        copy($xlsx, $tmp);

        return new UploadedFile(
            $tmp,
            '11-ericwesselink7.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    /** @param list<list<string>> $rows */
    private function writeXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');

        $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $cols) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach ($cols as $c => $value) {
                $ref = chr(65 + $c).($r + 1);
                $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        return $path;
    }
}
