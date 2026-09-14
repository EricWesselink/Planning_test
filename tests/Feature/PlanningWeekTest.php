<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use App\Services\PlanningBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PlanningWeekTest extends TestCase
{
    use RefreshDatabase;

    public function test_week_number_opens_that_iso_week(): void
    {
        $request = Request::create('/planning', 'GET', [
            'week_nr' => 40,
            'year' => 2026,
        ]);
        $data = app(PlanningBoardService::class)->build($request);

        $this->assertSame('2026-09-28', $data['weekStart']->toDateString());
        $this->assertSame(40, $data['weekStart']->isoWeek());
        $this->assertSame('Week 40 · september–oktober', $data['weekBands'][0]['label']);
        $this->assertSame('september–oktober', $data['weekBands'][0]['month']);
        $this->assertSame('Week 40', $data['weekRangeLabel']);
    }

    public function test_planning_week_includes_saturday_with_distinct_styling(): void
    {
        $request = Request::create('/planning', 'GET', [
            'week' => '2026-09-07',
            'weeks' => 1,
        ]);
        $data = app(PlanningBoardService::class)->build($request);

        $this->assertCount(6, $data['days']);
        $this->assertSame('2026-09-07', $data['days']->first()->toDateString());
        $this->assertSame('2026-09-12', $data['days']->last()->toDateString());
        $this->assertTrue($data['days']->last()->isSaturday());
        $this->assertSame(6, $data['weekBands'][0]['span']);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('zaterdag')
            ->assertSee('maandag')
            ->assertSee('7 september')
            ->assertSee('plan-day-weekday', false)
            ->assertSee('plan-day-date', false)
            ->assertSee('is-saturday', false)
            ->assertSee('data-date="2026-09-12"', false)
            ->assertDontSee('data-date="2026-09-13"', false);
    }

    public function test_eight_weeks_show_a_week_number_band_per_week(): void
    {
        $request = Request::create('/planning', 'GET', [
            'week' => '2026-09-07',
            'weeks' => 8,
        ]);
        $data = app(PlanningBoardService::class)->build($request);

        $this->assertCount(8, $data['weekBands']);
        $this->assertSame('Week 37 · sep', $data['weekBands'][0]['label']);
        $this->assertSame('Week 44 · okt', $data['weekBands'][7]['label']);
        $this->assertSame('Week 37–44', $data['weekRangeLabel']);
    }

    public function test_planning_page_has_arrow_links_to_previous_and_next_week(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'status' => 'gepland']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<a class="planning-btn planning-btn--icon" href="[^"]*week=2026-08-31[^"]*" title="Vorige week" aria-label="Vorige week">/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<a class="planning-btn planning-btn--icon" href="[^"]*week=2026-09-14[^"]*" title="Volgende week" aria-label="Volgende week">/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<a class="planning-btn planning-btn--icon" href="[^"]*status=gepland[^"]*" title="Vorige week" aria-label="Vorige week">/',
            $html
        );
        $this->assertStringNotContainsString('>Vorige</a>', $html);
        $this->assertStringNotContainsString('>Volgende</a>', $html);
    }

    public function test_planning_page_has_a_button_to_the_current_week(): void
    {
        $this->travelTo('2026-11-04 10:00:00');

        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'status' => 'gepland']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<a class="planning-btn" href="[^"]*week=2026-11-02[^"]*" title="Ga naar deze week">Deze week<\/a>/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<a class="planning-btn" href="[^"]*status=gepland[^"]*" title="Ga naar deze week">Deze week<\/a>/',
            $html
        );
    }

    public function test_planning_without_week_opens_the_current_iso_week(): void
    {
        $this->travelTo('2026-09-16 10:00:00');

        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('planning'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="2026-09-14"', $html);
        $this->assertStringContainsString('Week 38', $html);
    }

    public function test_planning_page_shows_week_numbers_and_week_search(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'weeks' => 8]))
            ->assertOk()
            ->assertSee('Week 37')
            ->assertSee('Week 38')
            ->assertSee('sep')
            ->assertSee('okt')
            ->assertSee('name="week_nr"', false)
            ->assertSee('Toon');
    }

    public function test_searching_a_week_number_redirects_the_board_to_that_monday(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('planning', ['week_nr' => 12, 'year' => 2026, 'weeks' => 2]))
            ->assertOk()
            ->assertSee('Week 12')
            ->assertSee('Week 13')
            ->assertSee('mrt')
            ->assertSee('value="12"', false);
    }

    public function test_planning_header_is_sticky_and_includes_day_times(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/planning-page[\s\S]*planning-controls[\s\S]*planning-scroll-area[\s\S]*plan-line plan-line--head sticky-head[\s\S]*Werk[\s\S]*Opdracht[\s\S]*Gereed[\s\S]*Rest[\s\S]*%[\s\S]*08:00[\s\S]*10:00[\s\S]*12:00[\s\S]*14:00/',
            $html
        );
        $this->assertStringNotContainsString('>16:00</span>', $html);
        $this->assertSame(1, substr_count($html, 'plan-line plan-line--head sticky-head'));
        $this->assertStringContainsString('planning-controls', $html);
        $this->assertStringContainsString('planning-scroll-area', $html);
        $this->assertStringContainsString('day-start', $html);
        $this->assertTrue(
            strpos($html, 'planning-controls') < strpos($html, 'planning-scroll-area'),
            'Controls must stay above the scrollable board.'
        );
        $this->assertTrue(
            strpos($html, 'planning-scroll-area') < strpos($html, 'sticky-head'),
            'Day header must live inside the scrollable board area.'
        );
    }

    public function test_planning_hides_work_types_with_zero_quantity(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Stichting Griftland']);
        $project = Project::query()->create([
            'project_number' => '251000088',
            'customer_id' => $customer->id,
            'name' => 'Nulregels college',
            'status' => 'gepland',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Tarkett pvc Classics-English Oak, PVC',
            'unit' => 'm2',
            'ordered_quantity' => 6587,
            'status' => 'gepland',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Tarkett vinyl iQ Natural-dark war',
            'unit' => 'm2',
            'ordered_quantity' => 0,
            'status' => 'gepland',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PU gietvloer , Ral',
            'unit' => 'm2',
            'ordered_quantity' => 0,
            'status' => 'gepland',
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('PVC')
            ->assertSee('6.587')
            ->assertDontSee('Vinyl')
            ->assertDontSee('Gietvloer');
    }

    public function test_planning_work_rows_use_compact_min_height(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Stichting Griftland']);
        $project = Project::query()->create([
            'project_number' => '251000088',
            'customer_id' => $customer->id,
            'name' => 'Compacte rijen college',
            'status' => 'gepland',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Tarkett pvc Classics-English Oak, PVC',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'gepland',
        ]);

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('plan-line plan-line--work', $html);
        $this->assertStringContainsString('min-height: 28px', $html);
        $this->assertStringNotContainsString('min-height: 36px', $html);
    }

    public function test_planning_frozen_columns_leave_room_for_the_day_grid(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/:root\s*\{[^}]*--planning-sidebar-width:\s*360px/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.plan-board\s*\{[^}]*--planning-sidebar-width:\s*360px/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.plan-line\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*var\(--planning-sidebar-width\)\)/',
            $css
        );
        $this->assertStringContainsString('--plan-col-opdracht: 55px', $css);
        $this->assertStringContainsString('--plan-col-gereed: 45px', $css);
        $this->assertStringContainsString('--plan-col-rest: 45px', $css);
        $this->assertStringContainsString('--plan-col-pct: 26px', $css);
        $this->assertStringNotContainsString('--plan-frozen', $css);
        $this->assertDoesNotMatchRegularExpression('/\.plan-board\s*\{[^}]*--planning-sidebar-width:\s*(400|520|560|600)px/', $css);
        $this->assertMatchesRegularExpression(
            '/\.plan-project-title\s*\{[^}]*-webkit-line-clamp:\s*2/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.plan-cell--werk \.plan-project-numbers\s*\{[^}]*white-space:\s*nowrap/s',
            $css
        );
    }

    public function test_planning_number_columns_have_subtle_vertical_dividers(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertMatchesRegularExpression(
            '/\.plan-cell--num\s*\{[^}]*box-shadow:\s*inset 1px 0 0 #b8b0a4/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.sticky-head \.plan-cell--num\s*\{[^}]*box-shadow:\s*none/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.plan-line--small \.plan-frozen > \.plan-cell--num\s*\{[^}]*box-shadow:\s*none/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.plan-line--section \.plan-cell--num\s*\{[^}]*box-shadow:\s*inset 1px 0 0 rgba\(255,\s*255,\s*255,\s*0\.22\)/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.plan-frozen--head \.plan-cell,\s*\.plan-frozen--head \.plan-labor-block\s*\{[^}]*grid-row:\s*2/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.plan-frozen--head > \.plan-cell,\s*\.plan-frozen--head > \.plan-labor-block\s*\{[^}]*grid-row:\s*2/s',
            $css
        );
    }

    public function test_planning_board_shows_compact_project_numbers(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Gemeente Dronten']);
        $project = Project::query()->create([
            'project_number' => '251100081',
            'customer_id' => $customer->id,
            'name' => '11P240897 Nieuwbouw Almere college WWL+',
            'city' => 'Dronten',
            'status' => 'gepland',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'gepland',
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('plan-project-numbers', false)
            ->assertSee('plan-project-title', false)
            ->assertSee('11P240897 · 251100081', false);
    }

    public function test_planning_shows_percent_complete_per_part_and_for_the_whole_work(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Gemeente Dronten']);
        $project = Project::query()->create([
            'project_number' => '251100081',
            'customer_id' => $customer->id,
            'name' => 'Nieuwbouw Almere college WWL+',
            'city' => 'Dronten',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        $primen = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 1100,
            'status' => 'in_uitvoering',
        ]);
        $linoleum = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 1100,
            'status' => 'in_uitvoering',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plinten',
            'unit' => 'm1',
            'ordered_quantity' => 50,
            'status' => 'gepland',
        ]);
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $primen->id,
            'date' => '2026-09-08',
            'completed_quantity' => 117,
            'unit' => 'm2',
        ]);
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $linoleum->id,
            'date' => '2026-09-08',
            'completed_quantity' => 53,
            'unit' => 'm2',
        ]);

        $request = Request::create('/planning', 'GET', [
            'week' => '2026-09-07',
            'project_id' => $project->id,
        ]);
        $request->setUserResolver(fn () => $user);
        $row = collect(app(PlanningBoardService::class)->build($request)['rows'])
            ->firstWhere('id', $project->id);

        $this->assertSame(2200.0, $row['ordered']);
        $this->assertSame(170.0, $row['completed']);
        $this->assertSame(2030.0, $row['remaining']);
        $this->assertSame(8, $row['percent']);
        $this->assertSame('m²', $row['unit']);

        $byTitle = collect($row['children'])->keyBy('title');
        $this->assertSame(11, $byTitle['Primen & Egaliseren']['percent']);
        $this->assertSame(5, $byTitle['Linoleum']['percent']);
        $this->assertSame(0, $byTitle['Plinten']['percent']);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('11%')
            ->assertSee('5%')
            ->assertSee('8%')
            ->assertSee('0%')
            ->assertSee('2.200 m²')
            ->assertSee('1.100 m²');
    }
}
