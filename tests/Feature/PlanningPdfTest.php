<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_page_links_to_client_and_internal_pdf(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Weekplanning vakmannen')
            ->assertSee('Intern')
            ->assertSee('Week')
            ->assertSee('Maand')
            ->assertSee('Gehele werk')
            ->assertSee('action="'.url('/planning/pdf').'"', false)
            ->assertSee('name="period"', false)
            ->assertSee('Weekplanning exporteren')
            ->assertSee('action="'.url('/planning/weekplanning').'"', false)
            ->assertSee('id="weekplanning-open"', false)
            ->assertSee('name="all"', false)
            ->assertSee('PDF maken')
            ->assertSee('Annuleren')
            ->assertDontSee('href="'.url('/planning/weekplanning?week=2026-09-07'), false);
    }

    public function test_client_pdf_hides_worker_names(): void
    {
        $user = User::factory()->create();
        [$project] = $this->seedPlanning();

        $this->actingAs($user)
            ->get(route('planning.export', [
                'week' => '2026-09-07',
                'project_id' => $project->id,
            ]))
            ->assertOk()
            ->assertSee('Opslaan als PDF')
            ->assertSee('Nicon Vloeren')
            ->assertSee('Opdrachtgever: Gemeente Amersfoort')
            ->assertSee('Laakse Tuinen Amersfoort')
            ->assertSee('Primen & Egaliseren')
            ->assertSee('Week 37')
            ->assertSee('Voor de opdrachtgever: zonder namen.')
            ->assertSee('0%')
            ->assertDontSee('Albert')
            ->assertDontSee('>Wie</th>', false)
            ->assertDontSee('School Zwolle')
            ->assertDontSee('Sleep een balk naar een andere dag')
            ->assertDontSee('Iemand inplannen')
            ->assertDontSee('Nicon Vloeren · Intern');
    }

    public function test_internal_pdf_shows_worker_names(): void
    {
        $user = User::factory()->create();
        [$project] = $this->seedPlanning();

        $this->actingAs($user)
            ->get(route('planning.export', [
                'week' => '2026-09-07',
                'project_id' => $project->id,
                'intern' => 1,
            ]))
            ->assertOk()
            ->assertSee('Nicon Vloeren · Intern')
            ->assertSee('Laakse Tuinen Amersfoort')
            ->assertSee('Primen & Egaliseren')
            ->assertSee('Albert')
            ->assertSee('Wie')
            ->assertSee('Intern: met namen van vakmensen.')
            ->assertDontSee('School Zwolle');
    }

    public function test_month_pdf_covers_every_week_in_the_month(): void
    {
        $user = User::factory()->create();
        [$project] = $this->seedPlanning();

        $this->actingAs($user)
            ->get(route('planning.export', [
                'week' => '2026-09-07',
                'project_id' => $project->id,
                'period' => 'month',
            ]))
            ->assertOk()
            ->assertSee('september 2026')
            ->assertSee('Week 36')
            ->assertSee('Week 40')
            ->assertSee('Laakse Tuinen Amersfoort')
            ->assertDontSee('School Zwolle');
    }

    public function test_work_pdf_covers_the_whole_project_period(): void
    {
        $user = User::factory()->create();
        [$project] = $this->seedPlanning();
        $project->forceFill(['planned_end_date' => '2026-10-02'])->save();

        $this->actingAs($user)
            ->get(route('planning.export', [
                'week' => '2026-09-07',
                'project_id' => $project->id,
                'period' => 'work',
            ]))
            ->assertOk()
            ->assertSee('Gehele werk')
            ->assertSee('Week 37')
            ->assertSee('Week 40')
            ->assertSee('Laakse Tuinen Amersfoort')
            ->assertDontSee('School Zwolle');
    }

    public function test_work_pdf_without_a_project_falls_back_to_the_week(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('planning.export', [
                'week' => '2026-09-07',
                'period' => 'work',
            ]))
            ->assertOk()
            ->assertSee('Kies eerst een werk in de filters om het hele werk te printen.')
            ->assertSee('Week 37')
            ->assertDontSee('Week 40');
    }

    public function test_guest_cannot_open_planning_pdf(): void
    {
        $this->get(route('planning.export'))
            ->assertRedirect(route('login'));
    }

    /**
     * @return array{0: Project, 1: Project}
     */
    private function seedPlanning(): array
    {
        $albert = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'color' => '#c2410c',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-11',
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 3835,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-11',
            'status' => 'in_uitvoering',
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $albert->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-07',
            'hours_per_day' => 8,
        ]);

        $otherCustomer = Customer::query()->create(['name' => 'Schoolbestuur']);
        $other = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $otherCustomer->id,
            'name' => 'School Zwolle',
            'city' => 'Zwolle',
            'status' => 'gepland',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-11',
        ]);
        WorkItem::query()->create([
            'project_id' => $other->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 200,
            'status' => 'gepland',
        ]);

        return [$project, $other];
    }
}
