<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_dashboard_redirects_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_new_work_opens_planning_with_klaar_date_and_days_left(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Gemeente Laren']);
        $project = Project::query()->create([
            'project_number' => '11P241267',
            'customer_id' => $customer->id,
            'name' => 'Gezondheidscentrum Laren',
            'city' => 'Laren',
            'status' => ProjectStatus::Gepland,
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-17',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Gezondheidscentrum Laren')
            ->assertSee('Klaar 17-09')
            ->assertSee('Nog 7 dagen')
            ->assertSee('Nog niet bemand')
            ->assertSee('/planning?project_id='.$project->id, false)
            ->assertSee('period=work', false)
            ->assertDontSee(route('projects.show', $project), false);
    }

    public function test_overview_bar_shows_work_count_and_keeps_egaliseren_apart_from_floor_laying(): void
    {
        $user = User::factory()->create();
        $running = $this->makeProject('TMZ Meubelenbelt', ProjectStatus::InUitvoering);
        $upcoming = $this->makeProject('Apotheek Zwolle', ProjectStatus::Gepland);
        $finished = $this->makeProject('Klaar werk', ProjectStatus::Gereed);
        $this->makeWorkItem($finished, 'PVC', 5000);
        $this->makeWorkItem($running, 'Egaliseren', 1000);
        $this->makeWorkItem($running, 'PVC', 800);
        $this->makeWorkItem($running, 'Plinten', 50, WorkUnit::LinearMeter);
        $this->makeWorkItem($running, 'Kitwerk', 12, WorkUnit::Pieces);
        $this->makeWorkItem($running, 'Begeleiding', 8, WorkUnit::Hours);
        $this->makeWorkItem($running, 'Herstel', 250, extra: true);
        $this->makeWorkItem($upcoming, 'Marmoleum', 400);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Werkoverzicht')
            ->assertSee('>2 werken</strong>', false)
            ->assertSee('>Egaliseren 1.000 m²</strong>', false)
            ->assertSee('>Materiaal leggen 1.200 m²</strong>', false)
            ->assertSee('>Deze week 0 m² gepland</strong>', false)
            ->assertSee('Nog niet bemand: 1 · Lopend: 1 · Vandaag: 0')
            ->assertDontSee('>Egaliseren 1.250 m²</strong>', false)
            ->assertDontSee('>Materiaal leggen 1.450 m²</strong>', false)
            ->assertDontSee('>Materiaal leggen 6.200 m²</strong>', false);
    }

    public function test_overview_bar_counts_only_square_meters_scheduled_this_week(): void
    {
        $user = User::factory()->create();
        $running = $this->makeProject('TMZ Meubelenbelt', ProjectStatus::InUitvoering);
        $upcoming = $this->makeProject('Apotheek Zwolle', ProjectStatus::Gepland);
        $egaliseren = $this->makeWorkItem($running, 'Primen & Egaliseren', 600);
        $pvc = $this->makeWorkItem($running, 'PVC', 900);
        $plinten = $this->makeWorkItem($running, 'Plinten', 180, WorkUnit::LinearMeter);
        $this->makeWorkItem($upcoming, 'Marmoleum', 400);
        $albert = $this->makeWorker('Albert');
        $jan = $this->makeWorker('Jan');
        $peter = $this->makeWorker('Peter');
        $this->assign($albert, $running, $egaliseren, '2026-09-08', '2026-09-12');
        $this->assign($jan, $running, $egaliseren, '2026-09-08', '2026-09-10');
        $this->assign($peter, $running, $pvc, '2026-09-10', '2026-09-18');
        $this->assign($albert, $running, $plinten, '2026-09-10', '2026-09-12');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('>2 werken</strong>', false)
            ->assertSee('>Egaliseren 600 m²</strong>', false)
            ->assertSee('>Materiaal leggen 1.300 m²</strong>', false)
            ->assertSee('>Deze week 900 m² gepland</strong>', false)
            ->assertSee('Nog niet bemand: 1 · Lopend: 1 · Vandaag: 1');
    }

    public function test_overview_bar_uses_shop_activity_square_meters_when_a_work_has_no_work_items(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject('Winkel Heerde', ProjectStatus::Gepland, ProjectKind::Winkel);
        $egaliseren = WorkActivity::query()->where('slug', 'egaliseren')->firstOrFail();
        $pvc = WorkActivity::query()->where('slug', 'pvc-banen')->firstOrFail();
        $plinten = WorkActivity::query()->where('slug', 'plinten')->firstOrFail();
        $screens = WorkActivity::query()->where('slug', 'screens')->firstOrFail();
        $project->workActivities()->sync([
            $egaliseren->id => ['quantity' => 120, 'unit' => WorkUnit::SquareMeter, 'sort_order' => 1],
            $pvc->id => ['quantity' => 100, 'unit' => WorkUnit::SquareMeter, 'sort_order' => 2],
            $plinten->id => ['quantity' => 40, 'unit' => WorkUnit::LinearMeter, 'sort_order' => 3],
            $screens->id => ['quantity' => 6, 'unit' => WorkUnit::Pieces, 'sort_order' => 4],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('>1 werk</strong>', false)
            ->assertSee('>Egaliseren 120 m²</strong>', false)
            ->assertSee('>Materiaal leggen 100 m²</strong>', false)
            ->assertDontSee('>Egaliseren 220 m²</strong>', false);
    }

    private function makeProject(string $name, ProjectStatus $status, ProjectKind $kind = ProjectKind::Project): Project
    {
        $customer = Customer::query()->create(['name' => 'Klant '.$name]);

        return Project::query()->create([
            'project_number' => '11P'.substr(md5($name), 0, 6),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Zwolle',
            'status' => $status,
            'kind' => $kind,
        ]);
    }

    private function makeWorkItem(
        Project $project,
        string $name,
        float $quantity,
        WorkUnit $unit = WorkUnit::SquareMeter,
        bool $extra = false,
    ): WorkItem {
        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'unit' => $unit,
            'ordered_quantity' => $quantity,
            'status' => 'gepland',
            'is_extra_work' => $extra,
        ]);
    }

    private function makeWorker(string $name): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'active' => true,
        ]);
    }

    private function assign(Worker $worker, Project $project, WorkItem $item, string $start, string $end): WorkerAssignment
    {
        return WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => $start,
            'end_date' => $end,
            'people_count' => 1,
        ]);
    }
}
