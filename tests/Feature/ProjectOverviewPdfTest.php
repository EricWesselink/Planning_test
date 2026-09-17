<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use App\Services\ProjectOverviewPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class ProjectOverviewPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_pdf_redirects_to_login(): void
    {
        $this->get(route('projects.pdf'))->assertRedirect(route('login'));
    }

    public function test_project_page_links_to_pdf_with_current_filters(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077', [
            'notes' => 'Referentie: 11P251047 Griftland college',
            'planned_start_date' => '2026-10-05',
        ]);

        $this->actingAs($user)
            ->get(route('projects.index', ['q' => 'Griftland', 'week' => 41, 'year' => 2026]))
            ->assertOk()
            ->assertSee('PDF projectenoverzicht')
            ->assertSee('href="'.e(route('projects.pdf', ['q' => 'Griftland', 'week' => 41, 'year' => 2026])).'"', false);
    }

    public function test_project_page_links_to_pdf_with_the_kind_filter(): void
    {
        $user = User::factory()->create();
        $this->makeProject('Jansen Hengelo screens', 'W26090001', [
            'kind' => ProjectKind::Winkel,
            'customer_id' => Customer::query()->create(['name' => 'Jansen'])->id,
            'city' => 'Hengelo',
        ]);

        $this->actingAs($user)
            ->get(route('projects.index', ['kind' => ProjectKind::Winkel->value]))
            ->assertOk()
            ->assertSee('href="'.e(route('projects.pdf', ['kind' => ProjectKind::Winkel->value])).'"', false);
    }

    public function test_pdf_lists_active_projects_in_nicon_letterhead_without_page_actions(): void
    {
        $user = User::factory()->create();
        $this->seedOverviewProjects();
        $this->travelTo('2026-09-14 19:26:00');

        $response = $this->actingAs($user)->get(route('projects.pdf'));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('Projectenoverzicht_14-09-2026-1926.pdf');
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));

        $text = $this->pdfText($response);
        $this->assertStringContainsString('NICON VLOEREN', $text);
        $this->assertStringContainsString('Projectenoverzicht', $text);
        $this->assertStringContainsString('Gegenereerd op: 14-09-2026 19:26', $text);
        $this->assertStringContainsString('Pagina', $text);
        $this->assertStringContainsString('11P251047', $text);
        $this->assertStringContainsString('251000077', $text);
        $this->assertStringContainsString('Griftland college', $text);
        $this->assertStringContainsString('Collegeweg 12', $text);
        $this->assertStringContainsString('3818 EW', $text);
        $this->assertStringContainsString('07-09-2026', $text);
        $this->assertStringContainsString('19-09-2026', $text);
        $this->assertStringContainsString('In uitvoering', $text);
        $this->assertStringContainsString('120 / 860 m²', $text);
        $this->assertStringContainsString('Albert', $text);
        $this->assertStringContainsString('Laakse Tuinen', $text);
        $this->assertStringContainsString('Het Vloerenhuis', $text);
        $this->assertStringContainsString('Actieve projecten', $text);
        $this->assertStringContainsString('Totaal m²', $text);
        $this->assertStringContainsString('Ingepland', $text);
        $this->assertStringContainsString('1.260', $text);
        $this->assertStringNotContainsString('Archiveren', $text);
        $this->assertStringNotContainsString('Verwijderen', $text);
        $this->assertStringNotContainsString('Opslaan', $text);
        $this->assertStringNotContainsString('Archief college', $text);
    }

    public function test_pdf_keeps_only_the_current_search_results(): void
    {
        $user = User::factory()->create();
        $this->seedOverviewProjects();

        $text = $this->pdfText($this->actingAs($user)->get(route('projects.pdf', ['q' => 'Griftland'])));

        $this->assertStringContainsString('Griftland college', $text);
        $this->assertStringContainsString('Selectie: zoekopdracht', $text);
        $this->assertStringContainsString('Griftland', $text);
        $this->assertStringNotContainsString('Laakse Tuinen', $text);
        $this->assertStringNotContainsString('Zonder start', $text);
    }

    public function test_pdf_keeps_only_projects_starting_in_the_selected_week(): void
    {
        $user = User::factory()->create();
        $this->seedOverviewProjects();
        $this->travelTo('2026-09-14 12:00:00');

        $text = $this->pdfText($this->actingAs($user)->get(route('projects.pdf', ['week' => 41, 'year' => 2026])));

        $this->assertStringContainsString('Laakse Tuinen', $text);
        $this->assertStringContainsString('week 41', $text);
        $this->assertStringContainsString('05-10-2026', $text);
        $this->assertStringNotContainsString('Griftland college', $text);
        $this->assertStringNotContainsString('Zonder start', $text);
    }

    public function test_pdf_keeps_only_winkelwerk_when_kind_is_winkel(): void
    {
        $user = User::factory()->create();
        $this->seedOverviewProjects();
        $this->makeProject('Jansen Hengelo screens', 'W26090001', [
            'kind' => ProjectKind::Winkel,
            'customer_id' => Customer::query()->create(['name' => 'Jansen'])->id,
            'city' => 'Hengelo',
        ]);

        $text = $this->pdfText($this->actingAs($user)->get(route('projects.pdf', ['kind' => ProjectKind::Winkel->value])));

        $this->assertStringContainsString('Jansen - Hengelo', $text);
        $this->assertStringContainsString('winkelwerk', $text);
        $this->assertStringNotContainsString('Griftland college', $text);
        $this->assertStringNotContainsString('Laakse Tuinen', $text);
    }

    public function test_pdf_does_not_reveal_inaccessible_projects(): void
    {
        $visible = $this->makeProject('11P251047 Griftland college', '251000077', [
            'notes' => 'Referentie: 11P251047 Griftland college',
        ]);
        $this->makeProject('11P251047 Extra college', '251000099', [
            'notes' => 'Referentie: 11P251047 Extra college',
        ]);
        $user = User::factory()->limitedAccess()->create();
        $user->projects()->attach($visible);

        $text = $this->pdfText($this->actingAs($user)->get(route('projects.pdf', ['q' => '11P251047'])));

        $this->assertStringContainsString('Griftland college', $text);
        $this->assertStringNotContainsString('Extra college', $text);
    }

    public function test_pdf_does_not_change_project_records(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject('11P251047 Griftland college', '251000077');
        $before = $project->fresh();

        $this->actingAs($user)->get(route('projects.pdf'))->assertOk();

        $after = $project->fresh();
        $this->assertSame($before->name, $after->name);
        $this->assertSame($before->planned_start_date?->toDateString(), $after->planned_start_date?->toDateString());
        $this->assertSame($before->updated_at?->toJSON(), $after->updated_at?->toJSON());
        $this->assertSame(1, Project::query()->count());
    }

    public function test_escapes_dangerous_content_in_the_pdf_html(): void
    {
        $user = User::factory()->create();
        $this->makeProject("<script>alert('xss')</script>", '251000077', [
            'address' => '<img src=x onerror=alert(\'addr\')>',
            'city' => 'Amersfoort',
        ]);

        $request = Request::create('/projecten/pdf', 'GET');
        $request->setUserResolver(fn () => $user);
        $html = view('projects.overview-pdf', app(ProjectOverviewPdfService::class)->build($request))->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString("<script>alert('xss')</script>", $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert', $html);
        $this->assertStringNotContainsString("<img src=x onerror=alert('addr')>", $html);
    }

    public function test_pdf_uses_multiple_pages_when_the_table_does_not_fit(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 28) as $index) {
            $this->makeProject('Project '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), '26020'.str_pad((string) $index, 4, '0', STR_PAD_LEFT), [
                'address' => 'Lange Straatnaam '.$index,
                'postal_code' => '3800 AA',
                'city' => 'Amersfoort',
                'planned_start_date' => '2026-09-07',
                'planned_end_date' => '2026-10-02',
                'status' => 'in_uitvoering',
            ]);
        }

        $text = $this->pdfText($this->actingAs($user)->get(route('projects.pdf')));

        $this->assertStringContainsString('Project 01', $text);
        $this->assertStringContainsString('Project 28', $text);
        $this->assertStringContainsString('Pagina 1 van', $text);
        $this->assertMatchesRegularExpression('/Pagina 1 van [2-9]/', $text);
        $this->assertGreaterThanOrEqual(2, substr_count($text, 'Pagina'));
    }

    /**
     * @return array{0: Project, 1: Project, 2: Project}
     */
    private function seedOverviewProjects(): array
    {
        $griftland = $this->makeProject('11P251047 Griftland college', '251000077', [
            'notes' => 'Referentie: 11P251047 Griftland college',
            'address' => 'Collegeweg 12',
            'postal_code' => '3818 EW',
            'city' => 'Amersfoort',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-19',
            'status' => 'in_uitvoering',
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $griftland->id,
            'name' => 'Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 860,
            'status' => 'in_uitvoering',
        ]);
        WorkProgressEntry::query()->create([
            'project_id' => $griftland->id,
            'work_item_id' => $item->id,
            'date' => '2026-09-08',
            'completed_quantity' => 120,
            'unit' => 'm2',
        ]);
        $albert = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $albert->id,
            'project_id' => $griftland->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-11',
            'hours_per_day' => 8,
        ]);

        $laakse = $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090', [
            'notes' => 'Referentie: 11P260141 Laakse Tuinen Amersfoort',
            'address' => 'Laakboulevard 8',
            'postal_code' => '3825 AL',
            'city' => 'Amersfoort',
            'planned_start_date' => '2026-10-05',
            'planned_end_date' => '2026-10-17',
            'status' => 'gepland',
        ]);
        WorkItem::query()->create([
            'project_id' => $laakse->id,
            'name' => 'Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 400,
            'status' => 'gepland',
        ]);
        $zzp = Worker::query()->create([
            'name' => 'Wepro',
            'employment_type' => 'zzp',
            'company' => 'Het Vloerenhuis',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $zzp->id,
            'project_id' => $laakse->id,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'hours_per_day' => 8,
        ]);

        $this->makeProject('Zonder start', '260200091', [
            'status' => 'concept',
        ]);
        $archived = $this->makeProject('Archief college', '260200092');
        $archived->archive();

        return [$griftland, $laakse, $archived];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProject(string $name, string $number, array $attributes = []): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create(array_merge([
            'project_number' => $number,
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'gepland',
        ], $attributes));
    }

    private function pdfText($response): string
    {
        $text = (new Parser)->parseContent($response->getContent())->getText();

        return preg_replace('/\s+/u', ' ', $text) ?? '';
    }
}
