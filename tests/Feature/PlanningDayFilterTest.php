<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanningDayFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_whole_week_is_the_default_and_the_removed_filters_are_gone(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('aria-label="Dag"', false)
            ->assertSee('>Hele week</option>', false)
            ->assertSee('>Maandag</option>', false)
            ->assertSee('>Dinsdag</option>', false)
            ->assertSee('>Woensdag</option>', false)
            ->assertSee('>Donderdag</option>', false)
            ->assertSee('>Vrijdag</option>', false)
            ->assertSee('>Zaterdag</option>', false)
            ->assertSee('name="kind"', false)
            ->assertSee('name="staffing"', false)
            ->assertSee('aria-label="Inplanning"', false)
            ->assertDontSee('Deze week met vakman')
            ->assertDontSee('Te plannen')
            ->assertDontSee('aria-label="Status"', false)
            ->assertDontSee('>Lopend</option>', false)
            ->assertDontSee('>Nieuw</option>', false)
            ->assertDontSee('name="todo_running"', false)
            ->getContent();

        $this->assertSame([
            '2026-09-07',
            '2026-09-08',
            '2026-09-09',
            '2026-09-10',
            '2026-09-11',
            '2026-09-12',
        ], $this->headerDates($html));
        $this->assertStringContainsString('--plan-days: 6', $html);
        $this->assertStringNotContainsString('plan-board--one-day', $html);
    }

    #[DataProvider('weekdays')]
    public function test_weekday_filter_shows_only_that_day_of_the_selected_week(int $day, string $date): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'day' => $day]))
            ->assertOk()
            ->assertSee('value="'.$day.'" selected', false)
            ->getContent();

        $this->assertSame([$date], $this->headerDates($html));
        $this->assertStringContainsString('plan-board--one-day', $html);
        $this->assertStringContainsString('--plan-days: 1; --plan-day-min: 0px', $html);
    }

    public function test_unknown_day_falls_back_to_the_whole_week(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'day' => 7]))
            ->assertOk()
            ->getContent();

        $this->assertCount(6, $this->headerDates($html));
        $this->assertStringNotContainsString('plan-board--one-day', $html);
    }

    public function test_day_filter_keeps_kind_staffing_and_worker_filters(): void
    {
        $user = User::factory()->create();
        $keesProject = $this->makeProject('250200101', 'Kees op woensdag');
        $pietProject = $this->makeProject('250200102', 'Piet op woensdag');
        $mondayProject = $this->makeProject('250200103', 'Alleen maandag');
        $kees = $this->makeWorker('Kees Dagfilter');
        $piet = $this->makeWorker('Piet Dagfilter');
        $this->assign($kees, $keesProject, '2026-09-09', '2026-09-09');
        $this->assign($piet, $pietProject, '2026-09-09', '2026-09-09');
        $this->assign($kees, $mondayProject, '2026-09-07', '2026-09-07');

        $html = $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'day' => 3,
                'kind' => ProjectKind::Project->value,
                'staffing' => 'planned',
                'who' => 'worker:'.$kees->id,
                'hours_view' => 'planned',
            ]))
            ->assertOk()
            ->assertSee('plan-project-title">Kees op woensdag', false)
            ->assertDontSee('plan-project-title">Piet op woensdag', false)
            ->assertDontSee('Alleen maandag')
            ->assertSee('value="'.ProjectKind::Project->value.'" selected', false)
            ->assertSee('value="planned" selected', false)
            ->assertSee('value="worker:'.$kees->id.'" selected', false)
            ->assertSee('value="3" selected', false)
            ->getContent();

        $this->assertSame(['2026-09-09'], $this->headerDates($html));
        $this->assertStringContainsString('data-worker-id="'.$kees->id.'"', $html);
        $this->assertStringNotContainsString('data-worker-id="'.$piet->id.'"', $html);
        $this->assertSame(ProjectStatus::Gepland, $keesProject->fresh()->status);
        $this->assertSame(ProjectStatus::Gepland, $mondayProject->fresh()->status);
    }

    public function test_previous_and_next_week_keep_the_selected_day(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'day' => 3,
                'kind' => ProjectKind::Project->value,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertSame(['2026-09-09'], $this->headerDates($html));
        $this->assertMatchesRegularExpression(
            '/<a class="planning-btn planning-btn--icon" href="(?=[^"]*week=2026-08-31)(?=[^"]*day=3)(?=[^"]*kind=project)[^"]*" title="Vorige week"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<a class="planning-btn planning-btn--icon" href="(?=[^"]*week=2026-09-14)(?=[^"]*day=3)(?=[^"]*kind=project)[^"]*" title="Volgende week"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<a class="planning-btn" href="(?=[^"]*day=3)(?=[^"]*kind=project)[^"]*" title="Ga naar deze week">/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/href="(?![^"]*day=)[^"]*" class="planning-filter planning-filter--reset"/',
            $html
        );

        $next = $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-14',
                'day' => 3,
                'kind' => ProjectKind::Project->value,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertSame(['2026-09-16'], $this->headerDates($next));
    }

    public function test_status_query_does_not_hide_projects_or_change_their_status(): void
    {
        $user = User::factory()->create();
        $planned = $this->makeProject('250200104', 'Status gepland blijft');
        $running = $this->makeProject('250200105', 'Status lopend blijft', ProjectStatus::InUitvoering);

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'status' => 'in_uitvoering',
            ]))
            ->assertOk()
            ->assertSee('Status gepland blijft')
            ->assertSee('Status lopend blijft')
            ->assertDontSee('status=in_uitvoering', false);

        $this->assertSame(ProjectStatus::Gepland, $planned->fresh()->status);
        $this->assertSame(ProjectStatus::InUitvoering, $running->fresh()->status);
    }

    public function test_one_day_layout_gives_the_day_column_the_narrow_screen(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $start = strpos($css, '@media (max-width: 768px), ((max-height: 520px) and (max-width: 1000px))');
        $end = strpos($css, '@media (max-height: 520px) and (max-width: 1000px)');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $mobile = substr($css, $start, $end - $start);

        $this->assertStringContainsString('overflow-x: hidden', $mobile);
        $this->assertStringContainsString('.planning-scroll-area', $mobile);
        $this->assertStringContainsString('overflow-x: auto', $mobile);
        $this->assertStringContainsString('overscroll-behavior-x: contain', $mobile);
        $this->assertStringContainsString('.plan-board.plan-board--one-day', $mobile);
        $this->assertStringContainsString('--planning-sidebar-width: 8.25rem', $mobile);
        $this->assertStringContainsString('--plan-day-min: 0px !important', $mobile);
        $this->assertStringContainsString('.plan-board--one-day .plan-table', $mobile);
        $this->assertStringContainsString('width: 100%', $mobile);
        $this->assertLessThan($start, strpos($css, '.plan-board--one-day .person-bar'));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function weekdays(): array
    {
        return [
            'maandag' => [1, '2026-09-07'],
            'dinsdag' => [2, '2026-09-08'],
            'woensdag' => [3, '2026-09-09'],
            'donderdag' => [4, '2026-09-10'],
            'vrijdag' => [5, '2026-09-11'],
            'zaterdag' => [6, '2026-09-12'],
        ];
    }

    /**
     * @return list<string>
     */
    private function headerDates(string $html): array
    {
        preg_match_all('/class="plan-day[^"]*" data-date="(\d{4}-\d{2}-\d{2})"/', $html, $matches);

        return $matches[1];
    }

    private function makeProject(string $number, string $name, ProjectStatus $status = ProjectStatus::Gepland): Project
    {
        $customer = Customer::query()->create(['name' => 'Klant '.$number]);
        $project = Project::query()->create([
            'project_number' => $number,
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Utrecht',
            'status' => $status,
            'kind' => ProjectKind::Project,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
            'status' => 'gepland',
        ]);

        return $project->fresh();
    }

    private function makeWorker(string $name): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'active' => true,
        ]);
    }

    private function assign(Worker $worker, Project $project, string $start, string $end): void
    {
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $project->workItems()->value('id'),
            'start_date' => $start,
            'end_date' => $end,
            'hours_per_day' => 8,
        ]);
    }
}
