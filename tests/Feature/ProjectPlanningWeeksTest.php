<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\PlanningBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ProjectPlanningWeeksTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_planning_week_update(): void
    {
        $project = $this->makeProject();

        $this->patch(route('projects.update', $project), [
            'start_year' => 2026,
            'start_week' => 40,
            'klaar_year' => 2026,
            'klaar_week' => 44,
        ])->assertRedirect(route('login'));
    }

    public function test_uitvoerder_cannot_change_planning_weeks(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Project bewerken')
            ->assertDontSee('Start werk');

        $this->actingAs($user)
            ->patch(route('projects.update', $project), [
                'start_year' => 2026,
                'start_week' => 40,
                'klaar_year' => 2026,
                'klaar_week' => 44,
            ])
            ->assertForbidden();

        $this->assertNull($project->fresh()->planned_start_date);
    }

    public function test_project_list_shows_start_and_klaar_fields(): void
    {
        $user = User::factory()->create();
        $this->makeProject();

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Start werk')
            ->assertSee('Klaar werk')
            ->assertSee('name="start_date"', false)
            ->assertSee('name="start_week"', false)
            ->assertSee('name="klaar_date"', false)
            ->assertSee('Opslaan')
            ->assertDontSee('>Datum</label>', false)
            ->assertDontSee('>Weeknummer</label>', false);
    }

    public function test_project_list_saves_weeks_and_updates_planning(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $item = $this->makeWorkItem($project, 'Linoleum');

        $this->actingAs($user)
            ->from(route('projects.index'))
            ->patch(route('projects.update', $project), [
                'planning_project_id' => $project->id,
                'start_year' => 2026,
                'start_week' => 40,
                'klaar_year' => 2026,
                'klaar_week' => 44,
            ])
            ->assertRedirect(route('projects.index'));

        $project->refresh();
        $item->refresh();
        $this->assertSame('2026-09-28', $project->planned_start_date?->toDateString());
        $this->assertSame('2026-10-31', $project->planned_end_date?->toDateString());
        $this->assertSame('2026-09-28', $item->planned_start_date?->toDateString());
        $this->assertSame('2026-10-31', $item->planned_end_date?->toDateString());
    }

    public function test_project_list_saves_exact_dates(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->from(route('projects.index'))
            ->patch(route('projects.update', $project), [
                'planning_project_id' => $project->id,
                'start_date' => '2026-10-14',
                'klaar_date' => '2026-11-03',
            ])
            ->assertRedirect(route('projects.index'));

        $project->refresh();
        $this->assertSame('2026-10-14', $project->planned_start_date?->toDateString());
        $this->assertSame('2026-11-03', $project->planned_end_date?->toDateString());

        $this->actingAs($user);
        $board = app(PlanningBoardService::class)->build(Request::create('/planning', 'GET', [
            'week' => '2026-10-12',
            'weeks' => 4,
            'project_id' => $project->id,
        ]));
        $this->assertNotNull($board['rows'][0]['bar']);
    }

    public function test_uitvoerder_cannot_edit_weeks_on_the_project_list(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $this->makeProject();

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Start werk')
            ->assertDontSee('name="start_week"', false)
            ->assertDontSee('>Opslaan<', false);
    }

    public function test_create_form_shows_optional_start_and_klaar_weeks(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('projects.create'))
            ->assertOk()
            ->assertSee('Start werk')
            ->assertSee('Klaar werk')
            ->assertSee('Weeknummer');
    }

    public function test_edit_form_shows_start_and_klaar_week_fields_for_existing_projects(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Project bewerken')
            ->assertSee('Start werk')
            ->assertSee('Klaar werk')
            ->assertSee('Weeknummer')
            ->assertSee('name="start_year"', false)
            ->assertSee('name="klaar_week"', false);
    }

    public function test_existing_project_can_get_start_and_klaar_weeks_later(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $item = $this->makeWorkItem($project, 'Linoleum');

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->patch(route('projects.update', $project), [
                'start_year' => 2026,
                'start_week' => 40,
                'klaar_year' => 2026,
                'klaar_week' => 44,
            ])
            ->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $item->refresh();
        $this->assertSame('2026-09-28', $project->planned_start_date?->toDateString());
        $this->assertSame('2026-10-31', $project->planned_end_date?->toDateString());
        $this->assertSame('2026-09-28', $item->planned_start_date?->toDateString());
        $this->assertSame('2026-10-31', $item->planned_end_date?->toDateString());

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Start werk: 2026 · week 40')
            ->assertDontSee('Klaar werk: 2026 · week 44')
            ->assertSee('Klaar werk');
    }

    public function test_changing_weeks_updates_planning_without_reimport(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject([
            'planned_start_date' => '2026-09-28',
            'planned_end_date' => '2026-10-31',
        ]);
        $aligned = $this->makeWorkItem($project, 'Linoleum', '2026-09-28', '2026-10-31');
        $custom = $this->makeWorkItem($project, 'Coating', '2026-10-05', '2026-10-10');

        $this->actingAs($user)
            ->patch(route('projects.update', $project), [
                'start_year' => 2026,
                'start_week' => 42,
                'klaar_year' => 2026,
                'klaar_week' => 46,
            ])
            ->assertRedirect();

        $project->refresh();
        $aligned->refresh();
        $custom->refresh();
        $this->assertSame('2026-10-12', $project->planned_start_date?->toDateString());
        $this->assertSame('2026-11-14', $project->planned_end_date?->toDateString());
        $this->assertSame('2026-10-12', $aligned->planned_start_date?->toDateString());
        $this->assertSame('2026-11-14', $aligned->planned_end_date?->toDateString());
        $this->assertSame('2026-10-05', $custom->planned_start_date?->toDateString());
        $this->assertSame('2026-10-10', $custom->planned_end_date?->toDateString());

        $this->actingAs($user);
        $moved = app(PlanningBoardService::class)->build(Request::create('/planning', 'GET', [
            'week_nr' => 42,
            'year' => 2026,
            'weeks' => 1,
            'project_id' => $project->id,
        ]));
        $this->assertNotNull($moved['rows'][0]['bar']);
        $this->assertSame(0, $moved['rows'][0]['bar']['start']);
        $this->assertSame(6, $moved['rows'][0]['bar']['span']);

        $oldWeek = app(PlanningBoardService::class)->build(Request::create('/planning', 'GET', [
            'week_nr' => 40,
            'year' => 2026,
            'weeks' => 1,
            'project_id' => $project->id,
        ]));
        $this->assertNull($oldWeek['rows'][0]['bar']);
    }

    public function test_rejects_a_klaar_week_before_the_start_week(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->patch(route('projects.update', $project), [
                'start_year' => 2026,
                'start_week' => 44,
                'klaar_year' => 2026,
                'klaar_week' => 40,
            ])
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHasErrors('klaar_week');

        $this->assertNull($project->fresh()->planned_start_date);
        $this->assertNull($project->fresh()->planned_end_date);
    }

    public function test_manual_create_can_store_start_and_klaar_weeks(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.store'), [
                'name' => 'School Zwolle',
                'customer_name' => 'Gemeente Zwolle',
                'start_year' => 2026,
                'start_week' => 40,
                'klaar_year' => 2026,
                'klaar_week' => 44,
            ])
            ->assertRedirect();

        $project = Project::query()->where('name', 'School Zwolle')->first();
        $this->assertNotNull($project);
        $this->assertSame('2026-09-28', $project->planned_start_date?->toDateString());
        $this->assertSame('2026-10-31', $project->planned_end_date?->toDateString());
    }

    public function test_manual_create_can_store_start_and_klaar_dates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.store'), [
                'name' => 'School Zwolle',
                'customer_name' => 'Gemeente Zwolle',
                'start_date' => '2026-10-14',
                'klaar_date' => '2026-11-03',
            ])
            ->assertRedirect();

        $project = Project::query()->where('name', 'School Zwolle')->first();
        $this->assertNotNull($project);
        $this->assertSame('2026-10-14', $project->planned_start_date?->toDateString());
        $this->assertSame('2026-11-03', $project->planned_end_date?->toDateString());
    }

    public function test_address_update_without_week_fields_keeps_existing_weeks(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject([
            'planned_start_date' => '2026-09-28',
            'planned_end_date' => '2026-10-31',
        ]);

        $this->actingAs($user)
            ->patch(route('projects.update', $project), [
                'address' => 'Schoolstraat 1',
                'city' => 'Amersfoort',
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('Schoolstraat 1', $project->address);
        $this->assertSame('2026-09-28', $project->planned_start_date?->toDateString());
        $this->assertSame('2026-10-31', $project->planned_end_date?->toDateString());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProject(array $overrides = []): Project
    {
        $customer = Customer::query()->create(['name' => 'Hegeman']);

        return Project::query()->create(array_merge([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => ProjectStatus::Gepland,
        ], $overrides));
    }

    private function makeWorkItem(Project $project, string $name, ?string $start = null, ?string $end = null): WorkItem
    {
        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 100,
            'planned_start_date' => $start,
            'planned_end_date' => $end,
            'status' => 'gepland',
        ]);
    }
}
