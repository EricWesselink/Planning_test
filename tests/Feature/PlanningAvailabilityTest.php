<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_page_shows_man_days_per_team_this_week(): void
    {
        $user = User::factory()->create();
        $kees = $this->makeWorker('Kees Jansen');
        $peter = $this->makeWorker('Peter', 'eigen');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-12');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Mandagen week')
            ->assertSee('planning-available-week-nr">37</span>', false)
            ->assertSee('planning-available-count">5</span>', false)
            ->assertSee('planning-available-name">Kees Jansen</span>', false)
            ->assertSee('--chip-color: '.$kees->planColor(), false)
            ->assertSee('<span class="planning-available-planned">0</span> / 5', false)
            ->assertSee('planning-available-free">5 vrij</span>', false)
            ->assertDontSee('planning-available-name">Peter</span>', false)
            ->assertDontSee('planning-available-free">0 vrij</span>', false);
    }

    public function test_planning_page_shows_zero_remaining_when_everyone_is_booked(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-12');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Mandagen week')
            ->assertSee('planning-available-count">0</span>', false)
            ->assertDontSee('planning-available-name">Peter</span>', false)
            ->assertDontSee('planning-available-free">0 vrij</span>', false);
    }

    public function test_planning_page_uses_the_period_label_for_multiple_weeks(): void
    {
        $user = User::factory()->create();
        $this->makeWorker('Kees Jansen');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'weeks' => 2]))
            ->assertOk()
            ->assertSee('Mandagen week')
            ->assertSee('planning-available-week-nr">37–38</span>', false)
            ->assertSee('planning-available-count">10</span>', false)
            ->assertSee('planning-available-name">Kees Jansen</span>', false)
            ->assertSee('<span class="planning-available-planned">0</span> / 10', false)
            ->assertSee('planning-available-free">10 vrij</span>', false);
    }

    public function test_planning_page_includes_teams_without_a_login(): void
    {
        $user = User::factory()->create();
        $this->makeWorker('Harm Wesselink');
        Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'people_count' => 3,
            'specialty' => 'Linoleum',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('planning-available-name">Harm Wesselink</span>', false)
            ->assertSee('planning-available-name">Kees Jansen</span>', false)
            ->assertSee('planning-available-count">20</span>', false);
    }

    public function test_planning_page_omits_own_staff_from_the_man_day_overview(): void
    {
        $user = User::factory()->create();
        $this->makeWorker('Kees Jansen');
        $this->makeWorker('Peter', 'eigen');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('planning-available-count">10</span>', false)
            ->assertSee('planning-available-name">Kees Jansen</span>', false)
            ->assertDontSee('planning-available-name">Peter</span>', false);
    }

    public function test_planning_page_omits_a_fully_unavailable_team(): void
    {
        $user = User::factory()->create();
        $this->makeWorker('Kees Jansen');
        $peter = $this->makeWorker('Peter', 'eigen');
        $peter->update(['unavailable' => true]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('planning-available-count">5</span>', false)
            ->assertDontSee('planning-available-name">Peter</span>', false);
    }

    public function test_planning_page_does_not_count_saturday_as_available(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-11');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('planning-available-count">0</span>', false);
    }

    public function test_planning_page_shows_planned_man_days_for_a_partly_booked_team(): void
    {
        $user = User::factory()->create();
        $team = Worker::query()->create([
            'name' => 'Wespro',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Piet', 'phone' => ''],
                ['name' => 'Jan', 'phone' => ''],
            ],
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $this->giveLogin($team);
        $item = $this->makeWorkItem();
        $this->assign($team, $item, '2026-09-07', '2026-09-09', 2);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('planning-available-count">4</span>', false)
            ->assertSee('planning-available-name">Wespro</span>', false)
            ->assertSee('<span class="planning-available-planned">6</span> / 10', false)
            ->assertSee('planning-available-free">4 vrij</span>', false)
            ->assertSee('width: 60%', false);
    }

    public function test_planning_page_counts_a_half_day_as_half_a_man_day(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-07', 1, '08:00:00', '12:00:00');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('planning-available-count">4,5</span>', false)
            ->assertSee('planning-available-name">Peter</span>', false)
            ->assertSee('<span class="planning-available-planned">0,5</span> / 5', false)
            ->assertSee('planning-available-free">4,5 vrij</span>', false);
    }

    public function test_planning_page_uses_the_singular_man_day_label(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-10');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('planning-available-count">1</span>', false)
            ->assertSee('planning-available-free">1 vrij</span>', false)
            ->assertDontSee('1 mandagen');
    }

    public function test_planning_page_escapes_team_names_in_the_man_day_overview(): void
    {
        $user = User::factory()->create();
        $this->makeWorker('Wespro <script>alert(1)</script>');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('planning-available-name">Wespro &lt;script&gt;alert(1)&lt;/script&gt;</span>', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_planning_page_recalculates_after_an_assignment_is_added(): void
    {
        $user = User::factory()->create();
        $team = Worker::query()->create([
            'name' => 'Wespro',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Piet', 'phone' => ''],
                ['name' => 'Jan', 'phone' => ''],
            ],
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $this->giveLogin($team);
        $item = $this->makeWorkItem();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('planning-available-name">Wespro</span>', false)
            ->assertSee('<span class="planning-available-planned">0</span> / 10', false)
            ->assertSee('planning-available-free">10 vrij</span>', false);

        $this->assign($team, $item, '2026-09-07', '2026-09-09', 2);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('planning-available-name">Wespro</span>', false)
            ->assertSee('<span class="planning-available-planned">6</span> / 10', false)
            ->assertSee('planning-available-free">4 vrij</span>', false);
    }

    private function makeWorker(string $name, string $employmentType = 'zzp'): Worker
    {
        $worker = Worker::query()->create([
            'name' => $name,
            'employment_type' => $employmentType,
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $this->giveLogin($worker);

        return $worker;
    }

    private function giveLogin(Worker $worker): void
    {
        User::factory()->vakman($worker->id)->create([
            'name' => $worker->name,
        ]);
    }

    private function makeWorkItem(): WorkItem
    {
        $customer = Customer::query()->create(['name' => 'Gemeente']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ]);

        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
    }

    private function assign(
        Worker $worker,
        WorkItem $item,
        string $start,
        string $end,
        int $peopleCount = 1,
        string $startTime = '08:00:00',
        string $endTime = '16:00:00',
    ): WorkerAssignment {
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $item->project_id,
            'work_item_id' => $item->id,
            'people_count' => $peopleCount,
        ]);
        $assignment->applySchedule(
            Carbon::parse($start),
            Carbon::parse($end),
            $startTime,
            $endTime,
        );
        $assignment->save();

        return $assignment;
    }
}
