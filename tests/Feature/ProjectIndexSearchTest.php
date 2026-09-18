<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectIndexSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_by_project_number_shows_only_matching_work(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077', 'Referentie: 11P251047 Griftland college');
        $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090', 'Referentie: 11P260141 Laakse Tuinen Amersfoort');

        $this->actingAs($user)
            ->get(route('projects.index', ['q' => '11P251047']))
            ->assertOk()
            ->assertSee('Griftland college')
            ->assertSee('11P251047')
            ->assertDontSee('Laakse Tuinen');
    }

    public function test_search_by_work_name_shows_only_matching_work(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090');

        $this->actingAs($user)
            ->get(route('projects.index', ['q' => 'Griftland']))
            ->assertOk()
            ->assertSee('Griftland college')
            ->assertDontSee('Laakse Tuinen');
    }

    public function test_search_by_work_number_shows_only_matching_work(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090');

        $this->actingAs($user)
            ->get(route('projects.index', ['q' => '251000077']))
            ->assertOk()
            ->assertSee('Griftland college')
            ->assertDontSee('Laakse Tuinen');
    }

    public function test_empty_search_shows_all_active_projects(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090');

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Zoek op projectnr. of werk')
            ->assertSee('Griftland college')
            ->assertSee('Laakse Tuinen');
    }

    public function test_search_does_not_reveal_inaccessible_projects(): void
    {
        $visible = $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeProject('11P251047 Extra college', '251000099');
        $user = User::factory()->limitedAccess()->create();
        $user->projects()->attach($visible);

        $this->actingAs($user)
            ->get(route('projects.index', ['q' => '11P251047']))
            ->assertOk()
            ->assertSee('Griftland college')
            ->assertDontSee('Extra college');
    }

    public function test_search_wildcard_characters_do_not_match_every_project(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090');

        $this->actingAs($user)
            ->get(route('projects.index', ['q' => '%']))
            ->assertOk()
            ->assertSee('Geen projecten voor')
            ->assertDontSee('Griftland college')
            ->assertDontSee('Laakse Tuinen');
    }

    public function test_week_filter_shows_only_projects_starting_that_week(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077', null, '2026-09-07');
        $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090', null, '2026-10-05');

        $this->actingAs($user)
            ->get(route('projects.index', ['week' => 41, 'year' => 2026]))
            ->assertOk()
            ->assertSee('Laakse Tuinen')
            ->assertDontSee('Griftland college');
    }

    public function test_kind_filter_is_shown_on_the_project_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('name="kind"', false)
            ->assertSee('>Alle werken</option>', false)
            ->assertSee('>Projecten</option>', false)
            ->assertSee('>Winkelwerk</option>', false)
            ->assertSee('>Kleine werken</option>', false);
    }

    public function test_winkelwerk_filter_hides_projects_and_small_works(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeWinkel('Jansen', 'Hengelo');
        $this->makeProject('Lekkage keuken', 'K26090001', null, null, ProjectKind::Klein);

        $this->actingAs($user)
            ->get(route('projects.index', ['kind' => ProjectKind::Winkel->value]))
            ->assertOk()
            ->assertSee('Jansen - Hengelo')
            ->assertDontSee('Griftland college')
            ->assertDontSee('Lekkage keuken');
    }

    public function test_projecten_filter_hides_winkelwerk_and_small_works(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeWinkel('Jansen', 'Hengelo');
        $this->makeProject('Lekkage keuken', 'K26090001', null, null, ProjectKind::Klein);

        $this->actingAs($user)
            ->get(route('projects.index', ['kind' => ProjectKind::Project->value]))
            ->assertOk()
            ->assertSee('Griftland college')
            ->assertDontSee('Jansen - Hengelo')
            ->assertDontSee('Lekkage keuken');
    }

    public function test_kleine_werken_filter_shows_klein_and_service(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeWinkel('Jansen', 'Hengelo');
        $this->makeProject('Lekkage keuken', 'K26090001', null, null, ProjectKind::Klein);
        $this->makeProject('Storingsdienst kantoor', 'S26090001', null, null, ProjectKind::Service);

        $this->actingAs($user)
            ->get(route('projects.index', ['kind' => ProjectKind::KLEINE_FILTER]))
            ->assertOk()
            ->assertSee('Lekkage keuken')
            ->assertSee('Storingsdienst kantoor')
            ->assertDontSee('Griftland college')
            ->assertDontSee('Jansen - Hengelo');
    }

    public function test_unknown_kind_shows_all_active_projects(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeWinkel('Jansen', 'Hengelo');

        $this->actingAs($user)
            ->get(route('projects.index', ['kind' => 'hack;drop']))
            ->assertOk()
            ->assertSee('Griftland college')
            ->assertSee('Jansen - Hengelo');
    }

    public function test_kind_filter_does_not_reveal_inaccessible_winkelwerk(): void
    {
        $visible = $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeWinkel('Jansen', 'Hengelo');
        $user = User::factory()->limitedAccess()->create();
        $user->projects()->attach($visible);

        $this->actingAs($user)
            ->get(route('projects.index', ['kind' => ProjectKind::Winkel->value]))
            ->assertOk()
            ->assertDontSee('Jansen - Hengelo')
            ->assertSee('Geen projecten voor deze selectie.');
    }

    public function test_kind_filter_does_not_change_projects(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject('11P251047 Griftland college', '251000077');
        $winkel = $this->makeWinkel('Jansen', 'Hengelo');

        $this->actingAs($user)
            ->get(route('projects.index', ['kind' => ProjectKind::Winkel->value]))
            ->assertOk();

        $this->assertSame(2, Project::query()->count());
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'kind' => ProjectKind::Project->value]);
        $this->assertDatabaseHas('projects', ['id' => $winkel->id, 'kind' => ProjectKind::Winkel->value]);
    }

    public function test_project_list_hides_assigned_workers_and_keeps_row_actions(): void
    {
        $user = User::factory()->admin()->create();
        $project = $this->makeProject('11P251047 Griftland college', '251000077');
        $worker = Worker::query()->create([
            'name' => 'Team 2 Peter',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-21',
            'end_date' => '2026-10-17',
            'people_count' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertDontSee('>Wie</th>', false)
            ->assertDontSee('Team 2 Peter')
            ->assertSee('Archiveren')
            ->assertSee('Verwijderen');

        $this->assertDatabaseHas('worker_assignments', [
            'project_id' => $project->id,
            'worker_id' => $worker->id,
        ]);
    }

    private function makeWinkel(string $customerName, string $city): Project
    {
        $customer = Customer::query()->create(['name' => $customerName]);

        return Project::query()->create([
            'project_number' => 'W26090001',
            'customer_id' => $customer->id,
            'name' => $customerName.' '.$city,
            'city' => $city,
            'status' => 'gepland',
            'kind' => ProjectKind::Winkel,
        ]);
    }

    private function makeProject(string $name, string $number, ?string $notes = null, ?string $start = null, ProjectKind $kind = ProjectKind::Project): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create([
            'project_number' => $number,
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'gepland',
            'kind' => $kind,
            'notes' => $notes,
            'planned_start_date' => $start,
        ]);
    }
}
