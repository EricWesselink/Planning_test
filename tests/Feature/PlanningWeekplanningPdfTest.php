<?php

namespace Tests\Feature;

use App\Enums\AvailabilityKind;
use App\Enums\ProjectKind;
use App\Enums\SmallWorkType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\WeekplanningPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class PlanningWeekplanningPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_weekplanning_pdf(): void
    {
        $this->get(route('planning.weekplanning', ['week' => '2026-09-07']))
            ->assertRedirect(route('login'));
    }

    public function test_pdf_opens_inline_for_the_selected_week(): void
    {
        $user = User::factory()->create();
        $this->seedFullDay('Albert', 'Laakse Tuinen');

        $this->travelTo('2026-09-11 09:00:00');

        $response = $this->actingAs($user)
            ->get(route('planning.weekplanning', ['week' => '2026-09-07']));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(
            'weekplanning-week-37-2026.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));

        $text = $this->pdfText($response);
        $this->assertStringContainsString('Weekplanning vakmannen', $text);
        $this->assertStringContainsString('Week 37', $text);
        $this->assertStringContainsString('Albert', $text);
        $this->assertStringContainsString('Laakse Tuinen', $text);
        $this->assertStringContainsString('Gegenereerd op 11-09-2026', $text);
        $this->assertStringContainsString('Pagina', $text);
        $this->assertStringNotContainsString('€/m²', $text);
        $this->assertStringNotContainsString('Begroot', $text);
        $this->assertStringNotContainsString('Nacalculatie', $text);
        $this->assertStringNotContainsString('kostprijs', $text);
    }

    public function test_full_day_assignment_shows_hele_dag(): void
    {
        $user = User::factory()->create();
        $this->seedFullDay('Albert', 'Laakse Tuinen');

        $text = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));

        $this->assertStringContainsString('Hele dag', $text);
        $this->assertStringContainsString('Albert', $text);
    }

    public function test_half_day_assignment_shows_hours_and_times(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Albert', 'Deventer – egaliseren');
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '12:00:00');

        $text = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));

        $this->assertStringContainsString('4 uur', $text);
        $this->assertStringContainsString('08:00–12:00', $text);
        $this->assertStringNotContainsString('Hele dag', $text);
    }

    public function test_two_jobs_on_the_same_day_are_both_visible(): void
    {
        $user = User::factory()->create();
        [$worker, $service, $serviceItem] = $this->makeProjectWorker('Eric', 'Deventer herstel', [
            'kind' => ProjectKind::Service,
            'name' => 'herstel',
            'city' => 'Deventer',
            'project_number' => '260200090',
        ]);
        [$other, $otherItem] = $this->makeProject('Laakse Tuinen', [
            'project_number' => '260200091',
            'city' => 'Amersfoort',
        ]);
        $this->assign($worker, $service, $serviceItem, '2026-09-07', '2026-09-07', '08:00:00', '12:00:00');
        $this->assign($worker, $other, $otherItem, '2026-09-07', '2026-09-07', '12:00:00', '16:00:00');

        $text = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));

        $this->assertStringContainsString('Eric', $text);
        $this->assertStringContainsString('Deventer', $text);
        $this->assertStringContainsString('Laakse Tuinen', $text);
        $this->assertStringContainsString('SERVICE', $text);
        $this->assertStringContainsString('08:00–12:00', $text);
        $this->assertStringContainsString('12:00–16:00', $text);
        $this->assertSame(1, substr_count($text, 'Eric'));
    }

    public function test_service_and_extra_are_labeled(): void
    {
        $user = User::factory()->create();
        [$eric, $service, $serviceItem] = $this->makeProjectWorker('Eric', 'herstel', [
            'kind' => ProjectKind::Service,
            'name' => 'herstel',
            'city' => 'Deventer',
            'project_number' => '260200090',
        ]);
        [$nick, $parent, $parentItem] = $this->makeProjectWorker('Nick', 'Gezondheidscentrum Laren', [
            'project_number' => '260200091',
            'city' => 'Laren',
        ]);
        $extra = WorkItem::query()->create([
            'project_id' => $parent->id,
            'name' => 'extra egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 0,
            'is_extra_work' => true,
            'small_work_type' => SmallWorkType::Extra,
            'status' => 'in_uitvoering',
        ]);
        $this->assign($eric, $service, $serviceItem, '2026-09-07', '2026-09-07', '08:00:00', '12:00:00');
        $this->assign($nick, $parent, $extra, '2026-09-08', '2026-09-08', '08:00:00', '12:00:00');
        $this->assign($nick, $parent, $parentItem, '2026-09-09', '2026-09-09', '08:00:00', '16:00:00');

        $text = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));

        $this->assertStringContainsString('SERVICE', $text);
        $this->assertStringContainsString('EXTRA', $text);
        $this->assertStringContainsString('extra egaliseren', $text);
        $this->assertPdfContains($text, 'Gezondheidscentrum Laren');
    }

    public function test_cards_show_the_work_address(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Wepro', 'Gezondheidscentrum Laren', [
            'address' => 'Nieuweweg 80',
            'postal_code' => '1251 LD',
            'city' => 'Laren',
        ]);
        $this->assign($worker, $project, $item, '2026-09-08', '2026-09-08', '08:00:00', '16:00:00');

        $request = Request::create('/planning/weekplanning', 'GET', ['week' => '2026-09-07']);
        $request->setUserResolver(fn () => $user);
        $data = app(WeekplanningPdfService::class)->build($request);
        $places = collect($data['people'])
            ->flatMap(fn (array $person): array => collect($person['days'])->flatten(1)->all())
            ->pluck('city')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['Nieuweweg 80, 1251 LD Laren'], $places);

        $html = view('planning.weekplanning', $data)->render();
        $this->assertStringContainsString('Nieuweweg 80, 1251 LD Laren', $html);

        $text = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));
        $this->assertStringContainsString('Nieuweweg 80', $text);
        $this->assertStringContainsString('1251 LD', $text);
    }

    public function test_cards_show_only_the_11p_number_when_a_work_number_also_exists(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Wepro', 'Gezondheidscentrum Laren', [
            'project_number' => '250100010',
            'name' => '11P241267 Gezondheidscentrum Laren',
            'notes' => 'Referentie: 11P241267 Gezondheidscentrum Laren',
            'city' => 'Laren',
        ]);
        $this->assign($worker, $project, $item, '2026-09-08', '2026-09-08', '08:00:00', '16:00:00');

        $request = Request::create('/planning/weekplanning', 'GET', ['week' => '2026-09-07']);
        $request->setUserResolver(fn () => $user);
        $data = app(WeekplanningPdfService::class)->build($request);
        $numbers = collect($data['people'])
            ->flatMap(fn (array $person): array => collect($person['days'])->flatten(1)->all())
            ->pluck('numbers')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['11P241267'], $numbers);
    }

    public function test_project_name_words_stay_intact_on_cards(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Wepro', 'Gezondheidscentrum Laren', [
            'city' => 'Laren',
        ]);
        $this->assign($worker, $project, $item, '2026-09-08', '2026-09-08', '08:00:00', '16:00:00');

        $request = Request::create('/planning/weekplanning', 'GET', ['week' => '2026-09-07']);
        $request->setUserResolver(fn () => $user);
        $html = view('planning.weekplanning', app(WeekplanningPdfService::class)->build($request))->render();

        $this->assertStringContainsString('block-title-word">Gezondheidscentrum</span>', $html);
        $this->assertStringContainsString('block-title-word">Laren</span>', $html);
        $this->assertStringNotContainsString('Gezondheidscentru</span>', $html);
    }

    public function test_marks_winkel_and_nicon_jobs_with_brand_and_logos(): void
    {
        $user = User::factory()->create();
        $owner = Customer::query()->create(['name' => 'Harm Wesselink']);
        [$worker, $winkel, $winkelItem] = $this->makeProjectWorker('Harm', 'Harm Wesselink - Zwolle', [
            'kind' => ProjectKind::Winkel,
            'customer_id' => $owner->id,
            'project_number' => '2026-003',
            'city' => 'Zwolle',
        ]);
        [$project, $item] = $this->makeProject('Gezondheidscentrum Laren', [
            'project_number' => '260200091',
            'city' => 'Laren',
        ]);
        $this->assign($worker, $winkel, $winkelItem, '2026-09-14', '2026-09-15', '08:00:00', '16:00:00');
        $this->assign($worker, $project, $item, '2026-09-16', '2026-09-18', '08:00:00', '16:00:00');

        $request = Request::create('/planning/weekplanning', 'GET', ['week' => '2026-09-14']);
        $request->setUserResolver(fn () => $user);
        $data = app(WeekplanningPdfService::class)->build($request);
        $labels = collect($data['people'])
            ->flatMap(fn (array $person): array => collect($person['days'])->flatten(1)->all())
            ->pluck('source_label', 'title');

        $this->assertSame('Harm', $labels->get('Harm Wesselink - Zwolle'));
        $this->assertSame('Harm', $labels->get('Gezondheidscentrum Laren'));
        $this->assertNotNull($data['logo']);
        $this->assertNotNull($data['shopLogo']);

        $html = view('planning.weekplanning', $data)->render();

        $this->assertStringContainsString('nicon-vloeren.png', $html);
        $this->assertStringContainsString('kloppenburg-interieur.png', $html);
        $this->assertStringContainsString('Nicon Vloeren', $html);
        $this->assertStringContainsString('Kloppenburg Interieur', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, '>Harm</td>'));
        $this->assertStringContainsString('class="block-brand-logo"', $html);
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'height="20" width="41"'));
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'height="20" width="80"'));
    }

    public function test_teammates_share_a_team_row_with_names_on_shared_and_solo_cards(): void
    {
        $user = User::factory()->create();
        $wepro = Worker::query()->create([
            'name' => 'Wepro',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Eric Wesselink', 'phone' => ''],
                ['name' => 'Harm Wesselink', 'phone' => ''],
            ],
            'active' => true,
        ]);
        [$project, $item] = $this->makeProject('Gezondheidscentrum Laren', [
            'city' => 'Laren',
        ]);
        $extra = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'vloer herstel',
            'unit' => 'm2',
            'ordered_quantity' => 0,
            'is_extra_work' => true,
            'small_work_type' => SmallWorkType::Extra,
            'status' => 'in_uitvoering',
        ]);
        $crew = $wepro->crewPeople()->orderBy('sort_order')->get();
        $this->assign($wepro, $project, $item, '2026-09-08', '2026-09-10', '08:00:00', '16:00:00', [$crew[0]->id, $crew[1]->id]);
        $this->assign($wepro, $project, $extra, '2026-09-11', '2026-09-11', '08:00:00', '16:00:00', [$crew[1]->id]);

        $request = Request::create('/planning/weekplanning', 'GET', ['week' => '2026-09-07']);
        $request->setUserResolver(fn () => $user);
        $data = app(WeekplanningPdfService::class)->build($request);

        $this->assertCount(1, $data['people']);
        $row = $data['people'][0];
        $this->assertSame('Wepro', $row['name']);
        $this->assertSame(['Eric Wesselink', 'Harm Wesselink'], $row['names']);
        $this->assertSame('Eric W. · Harm W.', $row['people_label']);
        $this->assertCount(1, $row['days']['2026-09-08']);
        $this->assertSame('Eric W. · Harm W.', $row['days']['2026-09-08'][0]['who']);
        $this->assertSame('Wepro', $row['days']['2026-09-08'][0]['source_label']);
        $this->assertSame('Gezondheidscentrum Laren', $row['days']['2026-09-08'][0]['title']);
        $this->assertCount(1, $row['days']['2026-09-11']);
        $this->assertSame('Harm W.', $row['days']['2026-09-11'][0]['who']);
        $this->assertSame('vloer herstel', $row['days']['2026-09-11'][0]['activity']);
        $this->assertSame($row['days']['2026-09-08'][0]['color'], $row['days']['2026-09-11'][0]['color']);

        $html = view('planning.weekplanning', $data)->render();
        $this->assertSame(1, substr_count($html, 'class="person-name">Wepro</div>'));
        $this->assertGreaterThanOrEqual(2, substr_count($html, '>Wepro</td>'));

        $response = $this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07']));
        $document = (new Parser)->parseContent($response->getContent());
        $text = preg_replace('/\s+/u', ' ', $document->getText()) ?? '';

        $this->assertCount(1, $document->getPages());
        $this->assertStringContainsString('Wepro', $text);
        $this->assertStringContainsString('Eric W.', $text);
        $this->assertStringContainsString('Harm W.', $text);
        $this->assertStringContainsString('vloer herstel', $text);
        $this->assertStringContainsString('EXTRA', $text);
        $this->assertSame(2, WorkerAssignment::query()->count());
    }

    public function test_crew_members_share_one_team_row_with_names_on_the_card(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Ploeg Noord',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Nick', 'phone' => ''],
                ['name' => 'Mahmoud', 'phone' => ''],
            ],
            'active' => true,
        ]);
        [$project, $item] = $this->makeProject('TWC Studentenhuisvesting');
        $people = $worker->crewPeople()->orderBy('sort_order')->get();
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-11', '08:00:00', '16:00:00', [$people[0]->id]);
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-11', '08:00:00', '16:00:00', [$people[1]->id]);

        $request = Request::create('/planning/weekplanning', 'GET', ['week' => '2026-09-07']);
        $request->setUserResolver(fn () => $user);
        $data = app(WeekplanningPdfService::class)->build($request);

        $this->assertCount(1, $data['people']);
        $this->assertSame('Ploeg Noord', $data['people'][0]['name']);
        $this->assertSame(['Mahmoud', 'Nick'], $data['people'][0]['names']);
        $this->assertCount(1, $data['people'][0]['days']['2026-09-07']);
        $this->assertSame('Mahmoud · Nick', $data['people'][0]['days']['2026-09-07'][0]['who']);

        $text = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));

        $this->assertStringContainsString('Nick', $text);
        $this->assertStringContainsString('Mahmoud', $text);
        $this->assertStringContainsString('Ploeg Noord', $text);
    }

    public function test_exporting_one_team_omits_other_scheduled_teams_and_names_the_file(): void
    {
        $user = User::factory()->create();
        $wepro = Worker::query()->create([
            'name' => 'Wepro',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $other = Worker::query()->create([
            'name' => 'Team 2',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        [$laren, $larenItem] = $this->makeProject('Gezondheidscentrum Laren', [
            'city' => 'Laren',
            'project_number' => '260200090',
        ]);
        [$tuinen, $tuinenItem] = $this->makeProject('Laakse Tuinen', [
            'city' => 'Amersfoort',
            'project_number' => '260200091',
        ]);
        $this->assign($wepro, $laren, $larenItem, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $this->assign($other, $tuinen, $tuinenItem, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('value="worker-'.$wepro->id.'"', false)
            ->assertSee('value="worker-'.$other->id.'"', false)
            ->assertSee('Wepro')
            ->assertSee('Team 2');

        $response = $this->actingAs($user)->get(route('planning.weekplanning', [
            'week' => '2026-09-07',
            'teams' => ['worker-'.$wepro->id],
        ]));
        $text = $this->pdfText($response);

        $this->assertStringContainsString('weekplanning-Wepro-week-37-2026.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Weekplanning – Wepro', $text);
        $this->assertPdfContains($text, 'Gezondheidscentrum Laren');
        $this->assertStringNotContainsString('Laakse Tuinen', $text);
        $this->assertStringNotContainsString('Team 2', $text);
        $this->assertSame(2, WorkerAssignment::query()->count());
    }

    public function test_vacation_day_is_shown_instead_of_a_dash(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Peter', 'TMZ Meubelenbelt');
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-10', '08:00:00', '16:00:00');
        $worker->availabilities()->create([
            'start_date' => '2026-09-11',
            'end_date' => '2026-09-11',
            'kind' => AvailabilityKind::Vacation,
        ]);

        $request = Request::create('/planning/weekplanning', 'GET', ['week' => '2026-09-07']);
        $request->setUserResolver(fn () => $user);
        $data = app(WeekplanningPdfService::class)->build($request);
        $friday = $data['people'][0]['days']['2026-09-11'][0];

        $this->assertTrue($friday['away']);
        $this->assertSame('Vakantie', $friday['title']);
        $this->assertSame('Hele dag', $friday['hours']);

        $html = view('planning.weekplanning', $data)->render();
        $this->assertStringContainsString('is-away', $html);
        $this->assertStringContainsString('Vakantie', $html);

        $text = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));
        $this->assertStringContainsString('Vakantie', $text);
        $this->assertStringContainsString('Peter', $text);
    }

    public function test_friday_off_is_shown_as_vrije_dag_and_saturday_stays_empty(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Peter', 'TMZ Meubelenbelt');
        $worker->update(['friday_off' => true]);
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-10', '08:00:00', '16:00:00');

        $request = Request::create('/planning/weekplanning', 'GET', ['week' => '2026-09-07']);
        $request->setUserResolver(fn () => $user);
        $data = app(WeekplanningPdfService::class)->build($request);
        $row = $data['people'][0];

        $this->assertSame('Vrije dag', $row['days']['2026-09-11'][0]['title']);
        $this->assertTrue($row['days']['2026-09-11'][0]['away']);
        $this->assertSame([], $row['days']['2026-09-12']);

        $text = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));
        $this->assertStringContainsString('Vrije dag', $text);
        $this->assertStringContainsString('—', $text);
    }

    public function test_one_teammate_weekday_off_is_named_on_the_card(): void
    {
        $user = User::factory()->create();
        $team = Worker::query()->create([
            'name' => 'Wepro',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Eric Wesselink', 'phone' => ''],
                ['name' => 'Harm Wesselink', 'phone' => ''],
            ],
            'active' => true,
        ]);
        $eric = $team->crewPeople->firstWhere('name', 'Eric Wesselink');
        $eric->setRelation('worker', $team);
        $eric->setWorkDay(3, false);
        $eric->save();
        $team->unsetRelation('crewPeople');
        [$project, $item] = $this->makeProject('Gezondheidscentrum Laren', [
            'city' => 'Laren',
        ]);
        $crew = $team->crewPeople()->orderBy('sort_order')->get();
        $this->assign($team, $project, $item, '2026-09-07', '2026-09-08', '08:00:00', '16:00:00', [$crew[0]->id, $crew[1]->id]);

        $request = Request::create('/planning/weekplanning', 'GET', ['week' => '2026-09-07']);
        $request->setUserResolver(fn () => $user);
        $data = app(WeekplanningPdfService::class)->build($request);
        $wednesday = $data['people'][0]['days']['2026-09-09'];

        $this->assertCount(1, $wednesday);
        $this->assertTrue($wednesday[0]['away']);
        $this->assertSame('Vrije dag', $wednesday[0]['title']);
        $this->assertSame('Eric W.', $wednesday[0]['who']);

        $text = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));
        $this->assertStringContainsString('Vrije dag', $text);
        $this->assertStringContainsString('Eric W.', $text);
    }

    public function test_vacation_without_work_that_week_stays_hidden(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Peter', 'TMZ Meubelenbelt');
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-10', '08:00:00', '16:00:00');
        $away = Worker::query()->create([
            'name' => 'AlleenVakantie',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $away->availabilities()->create([
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-11',
            'kind' => AvailabilityKind::Vacation,
        ]);

        $text = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));

        $this->assertStringContainsString('Peter', $text);
        $this->assertStringNotContainsString('AlleenVakantie', $text);
    }

    public function test_empty_day_shows_a_dash_and_unscheduled_people_are_hidden(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Peter', 'TMZ Meubelenbelt');
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-10', '08:00:00', '16:00:00');
        Worker::query()->create([
            'name' => 'NietIngepland',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $text = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));

        $this->assertStringContainsString('Peter', $text);
        $this->assertStringContainsString('—', $text);
        $this->assertStringNotContainsString('NietIngepland', $text);
    }

    public function test_uses_the_selected_week_from_the_board(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Albert', 'Week 37 werk');
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        [$later, $laterItem] = $this->makeProject('Week 38 werk', ['project_number' => '260200091']);
        $this->assign($worker, $later, $laterItem, '2026-09-14', '2026-09-14', '08:00:00', '16:00:00');

        $week37 = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07'])));
        $week38 = $this->pdfText($this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-14'])));

        $this->assertStringContainsString('Week 37', $week37);
        $this->assertStringContainsString('Week 37 werk', $week37);
        $this->assertStringNotContainsString('Week 38 werk', $week37);

        $this->assertStringContainsString('Week 38', $week38);
        $this->assertStringContainsString('Week 38 werk', $week38);
        $this->assertStringNotContainsString('Week 37 werk', $week38);
    }

    public function test_long_planning_spans_multiple_pages_with_repeated_day_headers(): void
    {
        $user = User::factory()->create();
        [$project, $item] = $this->makeProject('Groot werk');
        for ($index = 1; $index <= 24; $index++) {
            $worker = Worker::query()->create([
                'name' => sprintf('Vakman %02d', $index),
                'employment_type' => 'eigen',
                'active' => true,
            ]);
            $this->assign($worker, $project, $item, '2026-09-07', '2026-09-12', '08:00:00', '16:00:00');
        }

        $response = $this->actingAs($user)->get(route('planning.weekplanning', ['week' => '2026-09-07']));
        $document = (new Parser)->parseContent($response->getContent());
        $text = preg_replace('/\s+/u', ' ', $document->getText()) ?? '';

        $this->assertGreaterThan(1, count($document->getPages()));
        $this->assertGreaterThanOrEqual(2, substr_count($text, 'Maandag'));
        $this->assertStringContainsString('Vakman 01', $text);
        $this->assertStringContainsString('Vakman 24', $text);
    }

    public function test_escapes_dangerous_project_names_in_the_weekplanning_html(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Albert', "<script>alert('xss')</script>", [
            'address' => "<img src=x onerror=alert('addr')>",
            'city' => 'Amersfoort',
        ]);
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');

        $request = Request::create('/planning/weekplanning', 'GET', ['week' => '2026-09-07']);
        $request->setUserResolver(fn () => $user);
        $html = view('planning.weekplanning', app(WeekplanningPdfService::class)->build($request))->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString("<script>alert('xss')</script>", $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert', $html);
        $this->assertStringNotContainsString("<img src=x onerror=alert('addr')>", $html);
    }

    private function pdfText($response): string
    {
        $text = (new Parser)->parseContent($response->getContent())->getText();

        return preg_replace('/\s+/u', ' ', $text) ?? '';
    }

    private function assertPdfContains(string $text, string $needle): void
    {
        $haystack = preg_replace('/\s+/u', '', $text) ?? '';
        $want = preg_replace('/\s+/u', '', $needle) ?? '';

        $this->assertStringContainsString($want, $haystack);
    }

    private function seedFullDay(string $workerName, string $projectName): WorkerAssignment
    {
        [$worker, $project, $item] = $this->makeProjectWorker($workerName, $projectName);

        return $this->assign($worker, $project, $item, '2026-09-07', '2026-09-11', '08:00:00', '16:00:00');
    }

    /**
     * @param  array<string, mixed>  $projectAttributes
     * @return array{0: Worker, 1: Project, 2: WorkItem}
     */
    private function makeProjectWorker(string $workerName, string $projectName, array $projectAttributes = []): array
    {
        $worker = Worker::query()->create([
            'name' => $workerName,
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        [$project, $item] = $this->makeProject($projectName, $projectAttributes);

        return [$worker, $project, $item];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Project, 1: WorkItem}
     */
    private function makeProject(string $name, array $attributes = []): array
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Gemeente']);
        $project = Project::query()->create(array_merge([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ], $attributes));
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => $project->planned_start_date,
            'planned_end_date' => $project->planned_end_date,
            'status' => 'in_uitvoering',
        ]);

        return [$project, $item];
    }

    /**
     * @param  list<int>  $crewMemberIds
     */
    private function assign(
        Worker $worker,
        Project $project,
        WorkItem $item,
        string $startDate,
        string $endDate,
        string $startTime,
        string $endTime,
        array $crewMemberIds = [],
    ): WorkerAssignment {
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'hours_per_day' => 8,
            'planned_hours' => 8,
        ]);
        $assignment->applySchedule(
            $assignment->start_date,
            $assignment->end_date,
            $startTime,
            $endTime,
        );
        $assignment->save();
        if ($crewMemberIds !== []) {
            $assignment->syncPresentCrew($crewMemberIds);
        }

        return $assignment->fresh(['crewMembers']);
    }
}
