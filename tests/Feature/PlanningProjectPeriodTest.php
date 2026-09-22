<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\PlanningBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanningProjectPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_week_37_does_not_show_start_werk_before_the_project_start_date(): void
    {
        $user = User::factory()->create();
        $project = $this->makePeriodProject();

        $row = $this->boardRow($project, 37);

        $this->assertNull($row['bar']);
        $this->assertNull($row['start_marker']);
        $this->assertNull($row['end_marker']);
        $this->assertSame('14-09-2026 · week 38', $row['werk_start']);
        $this->assertTrue($row['missing_craftsman']);

        $this->actingAs($user)
            ->get(route('planning', ['week_nr' => 37, 'year' => 2026, 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('plan-werk-start', false)
            ->assertSee('▶ Start 14-09-2026 · week 38', false)
            ->assertDontSee('period-marker--start', false)
            ->assertDontSee('Start werk')
            ->assertDontSee('period-band', false)
            ->assertDontSee('Klaar werk');
    }

    public function test_week_38_shows_start_werk_on_14_september_and_warns_when_no_craftsman_is_planned(): void
    {
        $user = User::factory()->create();
        $project = $this->makePeriodProject();

        $row = $this->boardRow($project, 38);

        $this->assertNotNull($row['bar']);
        $this->assertSame(0, $row['bar']['start']);
        $this->assertSame(6, $row['bar']['span']);
        $this->assertSame(0, $row['start_marker']['index']);
        $this->assertSame('14-09-2026', $row['start_marker']['date']);
        $this->assertNull($row['end_marker']);
        $this->assertNull($row['werk_start']);
        $this->assertTrue($row['missing_craftsman']);
        $this->assertSame('2026-09-14', $row['start_week']);
        $this->assertSame([], $row['person_bars']);

        $this->actingAs($user)
            ->get(route('planning', ['week_nr' => 38, 'year' => 2026, 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('TWC studentenhuisvesting Utrecht')
            ->assertSee('period-band', false)
            ->assertSee('period-marker--start', false)
            ->assertSee('▶ Start', false)
            ->assertSee('14-09-2026')
            ->assertSee('⚠', false)
            ->assertSee('title="Ga naar startweek — geen vakman ingepland"', false)
            ->assertSee('week=2026-09-14', false)
            ->assertSee('project_id='.$project->id, false)
            ->assertDontSee('⚠ Geen vakman ingepland', false)
            ->assertDontSee('person-bar', false)
            ->assertDontSee('24-10-2026');
    }

    #[DataProvider('weeksInsideThePeriodWithoutEdgeDates')]
    public function test_weeks_inside_the_period_show_the_project_band_without_start_or_klaar_markers(int $weekNr): void
    {
        $user = User::factory()->create();
        $project = $this->makePeriodProject();

        $row = $this->boardRow($project, $weekNr);

        $this->assertNotNull($row['bar']);
        $this->assertSame(0, $row['bar']['start']);
        $this->assertSame(6, $row['bar']['span']);
        $this->assertNull($row['start_marker']);
        $this->assertNull($row['end_marker']);
        $this->assertNull($row['werk_start']);
        $this->assertTrue($row['missing_craftsman']);

        $this->actingAs($user)
            ->get(route('planning', ['week_nr' => $weekNr, 'year' => 2026, 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('period-band', false)
            ->assertSee('⚠', false)
            ->assertSee('title="Ga naar startweek — geen vakman ingepland"', false)
            ->assertSee('week=2026-09-14', false)
            ->assertSee('project_id='.$project->id, false)
            ->assertDontSee('⚠ Geen vakman ingepland', false)
            ->assertDontSee('plan-werk-start', false)
            ->assertDontSee('Start werk')
            ->assertDontSee('Klaar werk')
            ->assertDontSee('person-bar', false);
    }

    public function test_week_43_shows_klaar_werk_on_24_october_in_red_when_the_project_is_not_done(): void
    {
        $user = User::factory()->create();
        $project = $this->makePeriodProject();

        $row = $this->boardRow($project, 43);

        $this->assertNotNull($row['bar']);
        $this->assertSame(0, $row['bar']['start']);
        $this->assertSame(6, $row['bar']['span']);
        $this->assertNull($row['start_marker']);
        $this->assertSame(5, $row['end_marker']['index']);
        $this->assertSame('24-10-2026', $row['end_marker']['date']);
        $this->assertFalse($row['end_marker']['done']);

        $html = $this->actingAs($user)
            ->get(route('planning', ['week_nr' => 43, 'year' => 2026, 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('period-band', false)
            ->assertSee('period-marker--end', false)
            ->assertSee('Klaar werk')
            ->assertSee('24-10-2026')
            ->assertDontSee('Start werk')
            ->assertDontSee('14-09-2026')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/period-marker--end is-done/', $html);
        $this->assertDoesNotMatchRegularExpression('/period-marker--paired/', $html);
    }

    public function test_one_day_period_stacks_start_and_klaar_on_the_same_day(): void
    {
        $user = User::factory()->create();
        $project = $this->makePeriodProject();
        $project->forceFill([
            'planned_start_date' => '2026-09-22',
            'planned_end_date' => '2026-09-22',
        ])->save();
        $project->workItems()->update([
            'planned_start_date' => '2026-09-22',
            'planned_end_date' => '2026-09-22',
        ]);

        $row = $this->boardRow($project, 39);

        $this->assertNotNull($row['bar']);
        $this->assertSame(1, $row['bar']['start']);
        $this->assertSame(1, $row['bar']['span']);
        $this->assertSame(1, $row['start_marker']['index']);
        $this->assertSame(1, $row['end_marker']['index']);
        $this->assertSame('22-09-2026', $row['start_marker']['date']);
        $this->assertSame('22-09-2026', $row['end_marker']['date']);

        $this->actingAs($user)
            ->get(route('planning', ['week_nr' => 39, 'year' => 2026, 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('period-band', false)
            ->assertSee('period-marker--start period-marker--paired', false)
            ->assertSee('period-marker--end period-marker--paired', false)
            ->assertSee('▶ Start 22-09-2026', false)
            ->assertSee('Klaar 22-09-2026');
    }

    public function test_staffing_filter_shows_only_works_without_a_craftsman(): void
    {
        $user = User::factory()->create();
        $open = $this->makePeriodProject('250200015', 'TWC open Utrecht');
        $planned = $this->makePeriodProject('250200016', 'TWC gepland Utrecht');
        $item = $planned->workItems->first();
        $worker = Worker::query()->create([
            'name' => 'Kees',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $planned->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-15',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->get(route('planning', [
                'week_nr' => 38,
                'year' => 2026,
                'staffing' => 'open',
            ]))
            ->assertOk()
            ->assertSee('name="staffing"', false)
            ->assertSee('>Nog niet ingepland</option>', false)
            ->assertSee('TWC open Utrecht')
            ->assertDontSee('TWC gepland Utrecht')
            ->assertSee('plan-missing-craftsman', false);

        $this->actingAs($user)
            ->get(route('planning', [
                'week_nr' => 38,
                'year' => 2026,
                'staffing' => 'planned',
            ]))
            ->assertOk()
            ->assertSee('TWC gepland Utrecht')
            ->assertDontSee('TWC open Utrecht')
            ->assertDontSee('plan-missing-craftsman', false);
    }

    public function test_staffing_planned_only_shows_works_with_a_craftsman_in_the_visible_week(): void
    {
        $user = User::factory()->create();
        $thisWeek = $this->makePeriodProject('250200017', 'TWC deze week Utrecht');
        $otherWeek = $this->makePeriodProject('250200018', 'TWC andere week Utrecht');
        $worker = Worker::query()->create([
            'name' => 'Kees',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $thisWeek->id,
            'work_item_id' => $thisWeek->workItems->first()->id,
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-15',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $otherWeek->id,
            'work_item_id' => $otherWeek->workItems->first()->id,
            'start_date' => '2026-09-22',
            'end_date' => '2026-09-22',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->get(route('planning', [
                'week_nr' => 38,
                'year' => 2026,
                'staffing' => 'planned',
            ]))
            ->assertOk()
            ->assertSee('TWC deze week Utrecht')
            ->assertDontSee('TWC andere week Utrecht');
    }

    public function test_week_staffed_button_shows_every_work_with_a_craftsman_this_week(): void
    {
        $user = User::factory()->create();
        $nickWork = $this->makePeriodProject('250200019', 'Griftland college');
        $peterWork = $this->makePeriodProject('250200020', 'Laakse Tuinen');
        $openWork = $this->makePeriodProject('250200021', 'Open werk Utrecht');
        $nick = Worker::query()->create([
            'name' => 'Het Vloerenhuis',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $peter = Worker::query()->create([
            'name' => 'Peter',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $nickWork->id,
            'work_item_id' => $nickWork->workItems->first()->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-16',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $peter->id,
            'project_id' => $peterWork->id,
            'work_item_id' => $peterWork->workItems->first()->id,
            'start_date' => '2026-09-17',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->get(route('planning', [
                'week_nr' => 38,
                'year' => 2026,
                'worker_id' => $nick->id,
            ]))
            ->assertOk()
            ->assertSee('Deze week met vakman')
            ->assertSee(route('planning', [
                'week' => '2026-09-14',
                'weeks' => 1,
                'staffing' => 'planned',
            ]))
            ->assertSee('data-worker-id="'.$nick->id.'"', false)
            ->assertDontSee('data-worker-id="'.$peter->id.'"', false);

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-14',
                'weeks' => 1,
                'staffing' => 'planned',
            ]))
            ->assertOk()
            ->assertSee('Deze week met vakman')
            ->assertSee('planning-filter--staffed is-active', false)
            ->assertSee('Griftland college')
            ->assertSee('Laakse Tuinen')
            ->assertSee('data-worker-id="'.$nick->id.'"', false)
            ->assertSee('data-worker-id="'.$peter->id.'"', false)
            ->assertDontSee('Open werk Utrecht');
    }

    public function test_scheduling_a_craftsman_removes_the_warning_and_keeps_start_and_klaar_markers(): void
    {
        $user = User::factory()->create();
        $project = $this->makePeriodProject();
        $item = $project->workItems->first();
        $worker = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-16',
            'hours_per_day' => 8,
        ]);

        $startWeek = $this->boardRow($project, 38);
        $this->assertFalse($startWeek['missing_craftsman']);
        $this->assertSame('14-09-2026', $startWeek['start_marker']['date']);
        $this->assertSame([], $startWeek['person_bars']);

        $this->actingAs($user)
            ->get(route('planning', ['week_nr' => 38, 'year' => 2026, 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('▶ Start', false)
            ->assertSee('14-09-2026')
            ->assertSee('period-band', false)
            ->assertDontSee('Geen vakman ingepland')
            ->assertDontSee('Nog geen vakman ingepland');

        $this->actingAs($user)
            ->get(route('planning', ['week_nr' => 42, 'year' => 2026, 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('person-bar', false)
            ->assertDontSee('Geen vakman ingepland');

        $endWeek = $this->boardRow($project, 43);
        $this->assertFalse($endWeek['missing_craftsman']);
        $this->assertSame('24-10-2026', $endWeek['end_marker']['date']);
    }

    public function test_saving_new_project_dates_updates_the_week_planning_immediately(): void
    {
        $user = User::factory()->create();
        $project = $this->makePeriodProject();

        $this->actingAs($user)
            ->from(route('projects.index'))
            ->patch(route('projects.update', $project), [
                'planning_project_id' => $project->id,
                'start_date' => '2026-09-21',
                'klaar_date' => '2026-10-17',
            ])
            ->assertRedirect(route('projects.index'));

        $project->refresh();
        $this->assertSame('2026-09-21', $project->planned_start_date?->toDateString());
        $this->assertSame('2026-10-17', $project->planned_end_date?->toDateString());

        $week38 = $this->boardRow($project, 38);
        $this->assertNull($week38['bar']);
        $this->assertNull($week38['start_marker']);

        $week39 = $this->boardRow($project, 39);
        $this->assertNotNull($week39['bar']);
        $this->assertSame(0, $week39['start_marker']['index']);
        $this->assertSame('21-09-2026', $week39['start_marker']['date']);

        $week42 = $this->boardRow($project, 42);
        $this->assertSame(5, $week42['end_marker']['index']);
        $this->assertSame('17-10-2026', $week42['end_marker']['date']);

        $this->actingAs($user)
            ->get(route('planning', ['week_nr' => 39, 'year' => 2026, 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('▶ Start', false)
            ->assertSee('21-09-2026')
            ->assertDontSee('14-09-2026');
    }

    public function test_week_view_without_project_filter_still_shows_projects_outside_the_period(): void
    {
        $user = User::factory()->create();
        $this->makePeriodProject('250200015', 'TWC studentenhuisvesting Utrecht');
        $later = $this->makePeriodProject('250200099', 'Toekomstig werk Almere');
        $later->forceFill([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ])->save();
        $later->workItems()->update([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ]);

        $this->actingAs($user);
        $request = Request::create('/planning', 'GET', [
            'week_nr' => 38,
            'year' => 2026,
            'weeks' => 1,
        ]);
        $request->setUserResolver(fn () => $user);

        $board = app(PlanningBoardService::class)->build($request);
        $rows = collect($board['rows'])->where('type', 'project')->values();
        $titles = $rows->pluck('title')->all();

        $this->assertContains('TWC studentenhuisvesting Utrecht', $titles);
        $this->assertContains('Toekomstig werk Almere', $titles);

        $laterRow = $rows->firstWhere('title', 'Toekomstig werk Almere');
        $this->assertIsArray($laterRow);
        $this->assertNull($laterRow['bar']);
        $this->assertNull($laterRow['start_marker']);
        $this->assertNull($laterRow['end_marker']);
        $this->assertSame('02-11-2026 · week 45', $laterRow['werk_start']);

        $this->actingAs($user)
            ->get(route('planning', ['week_nr' => 38, 'year' => 2026, 'weeks' => 1]))
            ->assertOk()
            ->assertSee('TWC studentenhuisvesting Utrecht')
            ->assertSee('Toekomstig werk Almere')
            ->assertSee('plan-werk-start', false)
            ->assertSee('▶ Start 02-11-2026 · week 45', false);
    }

    public function test_running_work_appears_above_later_starts_and_finished_work(): void
    {
        $user = User::factory()->create();
        $later = $this->makePeriodProject('250200099', 'Later start Almere');
        $later->forceFill([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ])->save();
        $later->workItems()->update([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ]);
        $finished = $this->makePeriodProject('250200001', 'Klaar in augustus');
        $finished->forceFill([
            'planned_start_date' => '2026-08-03',
            'planned_end_date' => '2026-08-08',
        ])->save();
        $finished->workItems()->update([
            'planned_start_date' => '2026-08-03',
            'planned_end_date' => '2026-08-08',
        ]);
        $this->makePeriodProject('250200015', 'TWC studentenhuisvesting Utrecht');

        $this->actingAs($user);
        $request = Request::create('/planning', 'GET', [
            'week_nr' => 38,
            'year' => 2026,
            'weeks' => 1,
        ]);
        $request->setUserResolver(fn () => $user);

        $board = app(PlanningBoardService::class)->build($request);
        $titles = collect($board['rows'])->where('type', 'project')->pluck('title')->all();

        $this->assertSame([
            'TWC studentenhuisvesting Utrecht',
            'Later start Almere',
            'Klaar in augustus',
        ], $titles);

        $this->actingAs($user)
            ->get(route('planning', ['week_nr' => 38, 'year' => 2026, 'weeks' => 1]))
            ->assertOk()
            ->assertSeeInOrder([
                'TWC studentenhuisvesting Utrecht',
                'Later start Almere',
                'Klaar in augustus',
            ]);
    }

    public function test_extra_work_in_the_visible_week_keeps_the_parent_project_on_the_board(): void
    {
        $user = User::factory()->create();
        $project = $this->makePeriodProject('250200080', 'Hoofdwerk later');
        $project->forceFill([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ])->save();
        $project->workItems()->update([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Nacalculatie plinten',
            'unit' => 'm1',
            'ordered_quantity' => 40,
            'is_extra_work' => true,
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-16',
            'status' => 'gepland',
        ]);

        $this->actingAs($user);
        $request = Request::create('/planning', 'GET', [
            'week_nr' => 38,
            'year' => 2026,
            'weeks' => 1,
        ]);
        $request->setUserResolver(fn () => $user);

        $board = app(PlanningBoardService::class)->build($request);
        $titles = collect($board['rows'])->pluck('title')->all();

        $this->assertTrue(collect($titles)->contains(
            fn (mixed $title): bool => is_string($title) && str_contains($title, 'Nacalculatie plinten')
        ));
    }

    public function test_todo_running_button_toggles_and_shows_the_week_count(): void
    {
        $this->travelTo('2026-09-16 08:00:00');
        $user = User::factory()->create();
        $this->makePeriodProject('250200030', 'Open deze week Utrecht');
        $staffed = $this->makePeriodProject('250200031', 'Lopend met vakman Utrecht');
        $later = $this->makePeriodProject('250200032', 'Later start Almere');
        $later->forceFill([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ])->save();
        $later->workItems()->update([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ]);
        $worker = Worker::query()->create([
            'name' => 'Kees',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $staffed->id,
            'work_item_id' => $staffed->workItems->first()->id,
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-15',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->get(route('planning', [
                'week_nr' => 38,
                'year' => 2026,
                'kind' => 'project',
            ]))
            ->assertOk()
            ->assertSee('Te plannen + lopend (2)')
            ->assertSee(route('planning', [
                'week' => '2026-09-14',
                'weeks' => 1,
                'kind' => 'project',
                'hours_view' => 'planned',
                'todo_running' => '1',
            ]))
            ->assertDontSee('planning-filter--todo is-active', false)
            ->assertSee('Open deze week Utrecht')
            ->assertSee('Lopend met vakman Utrecht')
            ->assertSee('Later start Almere');

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-14',
                'weeks' => 1,
                'kind' => 'project',
                'todo_running' => '1',
            ]))
            ->assertOk()
            ->assertSee('Te plannen + lopend (2)')
            ->assertSee('planning-filter--todo is-active', false)
            ->assertSee('name="todo_running"', false)
            ->assertSee(route('planning', [
                'week' => '2026-09-14',
                'weeks' => 1,
                'kind' => 'project',
            ]))
            ->assertSee('Open deze week Utrecht')
            ->assertSee('Lopend met vakman Utrecht')
            ->assertDontSee('Later start Almere');
    }

    public function test_todo_running_filter_keeps_works_that_need_planning_or_are_running_this_week(): void
    {
        $user = User::factory()->create();
        $open = $this->makePeriodProject('250200033', 'Nog in te plannen Utrecht');
        $partial = $this->makePeriodProject('250200034', 'Gedeeltelijk ingepland Utrecht');
        WorkItem::query()->create([
            'project_id' => $partial->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 400,
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-10-24',
            'status' => 'gepland',
        ]);
        $running = $this->makePeriodProject('250200035', 'Lopend bezet Utrecht');
        $gereed = $this->makePeriodProject('250200036', 'Gereed deze week Utrecht');
        $gereed->forceFill(['status' => ProjectStatus::Gereed])->save();
        $finished = $this->makePeriodProject('250200037', 'Klaar in augustus');
        $finished->forceFill([
            'planned_start_date' => '2026-08-03',
            'planned_end_date' => '2026-08-08',
        ])->save();
        $finished->workItems()->update([
            'planned_start_date' => '2026-08-03',
            'planned_end_date' => '2026-08-08',
        ]);
        $later = $this->makePeriodProject('250200038', 'Start in week 45');
        $later->forceFill([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ])->save();
        $later->workItems()->update([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ]);
        $worker = Worker::query()->create([
            'name' => 'Piet',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $partial->id,
            'work_item_id' => $partial->workItems->first()->id,
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-15',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $running->id,
            'work_item_id' => $running->workItems->first()->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user);
        $titles = $this->boardTitles([
            'week_nr' => 38,
            'year' => 2026,
            'weeks' => 1,
            'todo_running' => '1',
        ], $user);

        $this->assertSame([
            'Nog in te plannen Utrecht',
            'Gedeeltelijk ingepland Utrecht',
            'Lopend bezet Utrecht',
        ], $titles);

        $assignment = WorkerAssignment::query()->where('project_id', $running->id)->first();
        $this->assertModelExists($assignment);
        $this->assertSame(8.0, (float) $assignment->hours_per_day);
        $this->assertSame('2026-09-14', $assignment->start_date->toDateString());
        $this->assertSame('2026-09-18', $assignment->end_date->toDateString());
        $this->assertSame(ProjectStatus::Gepland, $open->fresh()->status);
        $this->assertSame(ProjectStatus::Gereed, $gereed->fresh()->status);
    }

    public function test_todo_running_filter_follows_the_selected_week(): void
    {
        $user = User::factory()->create();
        $thisWeek = $this->makePeriodProject('250200039', 'Loopt in week 38');
        $thisWeek->forceFill([
            'planned_end_date' => '2026-09-19',
        ])->save();
        $thisWeek->workItems()->update([
            'planned_end_date' => '2026-09-19',
        ]);
        $later = $this->makePeriodProject('250200040', 'Start in week 40');
        $later->forceFill([
            'planned_start_date' => '2026-09-28',
            'planned_end_date' => '2026-10-09',
        ])->save();
        $later->workItems()->update([
            'planned_start_date' => '2026-09-28',
            'planned_end_date' => '2026-10-09',
        ]);

        $this->actingAs($user);

        $this->assertSame(['Loopt in week 38'], $this->boardTitles([
            'week_nr' => 38,
            'year' => 2026,
            'weeks' => 1,
            'todo_running' => '1',
        ], $user));
        $this->assertSame(['Start in week 40'], $this->boardTitles([
            'week_nr' => 40,
            'year' => 2026,
            'weeks' => 1,
            'todo_running' => '1',
        ], $user));

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-14',
                'weeks' => 1,
                'todo_running' => '1',
            ]))
            ->assertOk()
            ->assertSee('todo_running=1', false)
            ->assertSee('week=2026-09-21', false);
    }

    public function test_todo_running_filter_keeps_extra_work_this_week_without_the_later_parent(): void
    {
        $user = User::factory()->create();
        $project = $this->makePeriodProject('250200041', 'Hoofdwerk later');
        $project->forceFill([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ])->save();
        $project->workItems()->update([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Nacalculatie plinten',
            'unit' => 'm1',
            'ordered_quantity' => 40,
            'is_extra_work' => true,
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-16',
            'status' => 'gepland',
        ]);

        $this->actingAs($user);
        $rows = collect($this->boardRows([
            'week_nr' => 38,
            'year' => 2026,
            'weeks' => 1,
            'todo_running' => '1',
        ], $user));

        $this->assertTrue($rows->contains(
            fn (array $row): bool => str_contains((string) ($row['title'] ?? ''), 'Nacalculatie plinten')
        ));
        $this->assertFalse($rows->contains(
            fn (array $row): bool => ($row['type'] ?? '') === 'project' && ($row['title'] ?? '') === 'Hoofdwerk later'
        ));
    }

    public function test_todo_running_filter_combines_with_the_open_staffing_filter(): void
    {
        $user = User::factory()->create();
        $this->makePeriodProject('250200042', 'Open deze week');
        $staffed = $this->makePeriodProject('250200043', 'Bezet deze week');
        $later = $this->makePeriodProject('250200044', 'Open later');
        $later->forceFill([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ])->save();
        $later->workItems()->update([
            'planned_start_date' => '2026-11-02',
            'planned_end_date' => '2026-11-20',
        ]);
        $worker = Worker::query()->create([
            'name' => 'Kees',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $staffed->id,
            'work_item_id' => $staffed->workItems->first()->id,
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-15',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user);

        $this->assertSame(['Open deze week'], $this->boardTitles([
            'week_nr' => 38,
            'year' => 2026,
            'weeks' => 1,
            'staffing' => 'open',
            'todo_running' => '1',
        ], $user));
    }

    public function test_missing_craftsman_does_not_query_assignments_once_per_project(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $this->makePeriodProject('250200001', 'Project A');
        $this->makePeriodProject('250200002', 'Project B');
        $this->makePeriodProject('250200003', 'Project C');

        $lazyAssignmentQueries = 0;
        DB::listen(function ($query) use (&$lazyAssignmentQueries): void {
            if (
                str_contains($query->sql, 'worker_assignments')
                && preg_match('/project_id[`"\']?\s*=\s*\?/i', $query->sql)
            ) {
                $lazyAssignmentQueries++;
            }
        });

        $request = Request::create('/planning', 'GET', [
            'week_nr' => 38,
            'year' => 2026,
            'weeks' => 1,
        ]);
        $request->setUserResolver(fn () => $user);

        $board = app(PlanningBoardService::class)->build($request);
        $projects = collect($board['rows'])->where('type', 'project');

        $this->assertSame(3, $projects->count());
        $this->assertTrue($projects->every(fn (array $row): bool => $row['missing_craftsman'] === true));
        $this->assertSame(0, $lazyAssignmentQueries);
    }

    /** @return array<string, array{0: int}> */
    public static function weeksInsideThePeriodWithoutEdgeDates(): array
    {
        return [
            'week 39' => [39],
            'week 40' => [40],
            'week 41' => [41],
            'week 42' => [42],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function boardTitles(array $params, User $user): array
    {
        return collect($this->boardRows($params, $user))
            ->whereIn('type', ['project', 'small'])
            ->pluck('title')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function boardRows(array $params, User $user): array
    {
        $request = Request::create('/planning', 'GET', $params);
        $request->setUserResolver(fn () => $user);

        return app(PlanningBoardService::class)->build($request)['rows'];
    }

    /**
     * @return array<string, mixed>
     */
    private function boardRow(Project $project, int $weekNr): array
    {
        $board = app(PlanningBoardService::class)->build(Request::create('/planning', 'GET', [
            'week_nr' => $weekNr,
            'year' => 2026,
            'weeks' => 1,
            'project_id' => $project->id,
        ]));

        $this->assertNotEmpty($board['rows']);

        return $board['rows'][0];
    }

    private function makePeriodProject(string $projectNumber = '250200015', string $name = '11P230988 TWC studentenhuisvesting Utrecht'): Project
    {
        $customer = Customer::query()->create(['name' => 'TWC '.$projectNumber]);
        $project = Project::query()->create([
            'project_number' => $projectNumber,
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Utrecht',
            'status' => ProjectStatus::Gepland,
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-10-24',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 1200,
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-10-24',
            'status' => 'gepland',
        ]);

        return $project->fresh(['workItems']);
    }
}
