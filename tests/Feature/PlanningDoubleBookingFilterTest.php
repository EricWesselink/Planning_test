<?php

namespace Tests\Feature;

use App\Models\CrewMember;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PlanningDoubleBookingFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_double_planning_filter_shows_one_person_on_two_overlapping_works(): void
    {
        $user = User::factory()->create();
        [$worker, $crew] = $this->team('Wesselink', ['Eric Wesselink'], 'zzp', 'Wesselink Media');
        $eric = $crew['Eric Wesselink'];
        $alpha = $this->place($worker, $this->work('Werk Alpha'), '2026-09-08', [$eric->id]);
        $beta = $this->place($worker, $this->work('Werk Beta'), '2026-09-08', [$eric->id]);
        $quiet = $this->place($this->solo('Piet Alleen'), $this->work('Werk Rust'), '2026-09-08');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Bekijk dubbele planning')
            ->assertSee('Eric Wesselink')
            ->assertSee('double_crew='.$eric->id, false)
            ->assertSee('week=2026-09-07', false)
            ->assertSee('data-shift-id="'.$quiet->id.'"', false);

        $filtered = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'doubles' => 1]));

        $filtered->assertOk()
            ->assertSee('Dubbele planning (2 inzetten)', false)
            ->assertSee('Alles tonen')
            ->assertSee('data-shift-id="'.$alpha->id.'"', false)
            ->assertSee('data-shift-id="'.$beta->id.'"', false)
            ->assertDontSee('data-shift-id="'.$quiet->id.'"', false);

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'doubles' => 1,
                'double_crew' => $eric->id,
            ]))
            ->assertOk()
            ->assertSee('Dubbele planning · Eric Wesselink (2 inzetten)', false)
            ->assertSee('data-shift-id="'.$alpha->id.'"', false)
            ->assertSee('data-shift-id="'.$beta->id.'"', false)
            ->assertDontSee('data-shift-id="'.$quiet->id.'"', false);
    }

    public function test_double_planning_filter_shows_one_person_on_three_overlapping_works(): void
    {
        $user = User::factory()->create();
        [$worker, $crew] = $this->team('Wesselink', ['Eric Wesselink'], 'zzp', 'Wesselink Media');
        $eric = $crew['Eric Wesselink'];
        $alpha = $this->place($worker, $this->work('Werk Alpha'), '2026-09-08', [$eric->id]);
        $beta = $this->place($worker, $this->work('Werk Beta'), '2026-09-08', [$eric->id]);
        $gamma = $this->place($worker, $this->work('Werk Gamma'), '2026-09-08', [$eric->id]);
        $quiet = $this->place($this->solo('Piet Alleen'), $this->work('Werk Rust'), '2026-09-08');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'doubles' => 1]))
            ->assertOk()
            ->assertSee('Dubbele planning (3 inzetten)', false)
            ->assertSee('data-shift-id="'.$alpha->id.'"', false)
            ->assertSee('data-shift-id="'.$beta->id.'"', false)
            ->assertSee('data-shift-id="'.$gamma->id.'"', false)
            ->assertDontSee('data-shift-id="'.$quiet->id.'"', false);
    }

    public function test_double_planning_filter_shows_every_conflicting_person_and_one_person_alone(): void
    {
        $user = User::factory()->create();
        [$worker, $crew] = $this->team('Wesselink', ['Eric Wesselink', 'Harm Wesselink'], 'zzp', 'Wesselink Media');
        $eric = $crew['Eric Wesselink'];
        $harm = $crew['Harm Wesselink'];
        $alpha = $this->place($worker, $this->work('Werk Alpha'), '2026-09-08', [$eric->id]);
        $beta = $this->place($worker, $this->work('Werk Beta'), '2026-09-08', [$eric->id]);
        $charlie = $this->place($worker, $this->work('Werk Charlie'), '2026-09-08', [$harm->id]);
        $delta = $this->place($worker, $this->work('Werk Delta'), '2026-09-08', [$harm->id]);

        $zzp = $this->solo('Jansen', 'Jansen Vloeren');
        $echo = $this->place($zzp, $this->work('Werk Echo'), '2026-09-08');
        $foxtrot = $this->place($zzp, $this->work('Werk Foxtrot'), '2026-09-08');
        $quiet = $this->place($this->solo('Piet Alleen'), $this->work('Werk Rust'), '2026-09-08');

        $all = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'doubles' => 1]));
        $all->assertOk()
            ->assertSee('Dubbele planning (6 inzetten)', false)
            ->assertSee('data-shift-id="'.$alpha->id.'"', false)
            ->assertSee('data-shift-id="'.$beta->id.'"', false)
            ->assertSee('data-shift-id="'.$charlie->id.'"', false)
            ->assertSee('data-shift-id="'.$delta->id.'"', false)
            ->assertSee('data-shift-id="'.$echo->id.'"', false)
            ->assertSee('data-shift-id="'.$foxtrot->id.'"', false)
            ->assertDontSee('data-shift-id="'.$quiet->id.'"', false);

        $onlyEric = $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'doubles' => 1,
                'double_crew' => $eric->id,
            ]));
        $onlyEric->assertOk()
            ->assertSee('Eric Wesselink (2 inzetten)', false)
            ->assertSee('data-shift-id="'.$alpha->id.'"', false)
            ->assertSee('data-shift-id="'.$beta->id.'"', false)
            ->assertDontSee('data-shift-id="'.$charlie->id.'"', false)
            ->assertDontSee('data-shift-id="'.$delta->id.'"', false)
            ->assertDontSee('data-shift-id="'.$echo->id.'"', false)
            ->assertDontSee('data-shift-id="'.$quiet->id.'"', false);
    }

    public function test_double_planning_filter_hides_assignments_that_do_not_overlap(): void
    {
        $user = User::factory()->create();
        [$worker, $crew] = $this->team('Wesselink', ['Eric Wesselink'], 'zzp', 'Wesselink Media');
        $eric = $crew['Eric Wesselink'];
        $alpha = $this->place($worker, $this->work('Werk Alpha'), '2026-09-08', [$eric->id]);
        $beta = $this->place($worker, $this->work('Werk Beta'), '2026-09-08', [$eric->id]);
        $morning = $this->place($worker, $this->work('Werk Ochtend'), '2026-09-09', [$eric->id], '08:00:00', '12:00:00');
        $afternoon = $this->place($worker, $this->work('Werk Middag'), '2026-09-09', [$eric->id], '12:00:00', '16:00:00');
        $later = $this->place($worker, $this->work('Werk Later'), '2026-09-10', [$eric->id]);
        $quiet = $this->place($this->solo('Piet Alleen'), $this->work('Werk Rust'), '2026-09-08');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'doubles' => 1]))
            ->assertOk()
            ->assertSee('Dubbele planning (2 inzetten)', false)
            ->assertSee('data-shift-id="'.$alpha->id.'"', false)
            ->assertSee('data-shift-id="'.$beta->id.'"', false)
            ->assertDontSee('data-shift-id="'.$morning->id.'"', false)
            ->assertDontSee('data-shift-id="'.$afternoon->id.'"', false)
            ->assertDontSee('data-shift-id="'.$later->id.'"', false)
            ->assertDontSee('data-shift-id="'.$quiet->id.'"', false);
    }

    public function test_clearing_the_double_planning_filter_shows_the_full_week_again(): void
    {
        $user = User::factory()->create();
        [$worker, $crew] = $this->team('Wesselink', ['Eric Wesselink'], 'zzp', 'Wesselink Media');
        $eric = $crew['Eric Wesselink'];
        $alpha = $this->place($worker, $this->work('Werk Alpha'), '2026-09-08', [$eric->id]);
        $beta = $this->place($worker, $this->work('Werk Beta'), '2026-09-08', [$eric->id]);
        $quiet = $this->place($this->solo('Piet Alleen'), $this->work('Werk Rust'), '2026-09-08');
        $before = $this->assignmentSnapshot();

        $filtered = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'doubles' => 1]));
        $filtered->assertOk()
            ->assertSee('Alles tonen')
            ->assertDontSee('data-shift-id="'.$quiet->id.'"', false)
            ->assertSee('data-shift-id="'.$alpha->id.'"', false)
            ->assertSee('data-shift-id="'.$beta->id.'"', false);

        $this->assertSame(1, preg_match('/class="planning-doubles-clear"\s+href="([^"]*)"/', $filtered->getContent(), $clear));
        $clearUrl = html_entity_decode($clear[1]);
        $this->assertStringContainsString('week=2026-09-07', $clearUrl);
        $this->assertStringNotContainsString('doubles=', $clearUrl);

        $this->actingAs($user)
            ->get($clearUrl)
            ->assertOk()
            ->assertDontSee('planning-doubles-banner', false)
            ->assertSee('data-shift-id="'.$alpha->id.'"', false)
            ->assertSee('data-shift-id="'.$beta->id.'"', false)
            ->assertSee('data-shift-id="'.$quiet->id.'"', false);

        $this->assertSame($before, $this->assignmentSnapshot());
    }

    /**
     * @param  list<string>  $names
     * @return array{0: Worker, 1: Collection<string, CrewMember>}
     */
    private function team(string $name, array $names, string $employment, string $company): array
    {
        $members = [];
        foreach ($names as $person) {
            $members[] = ['name' => $person, 'phone' => ''];
        }

        $worker = Worker::query()->create([
            'name' => $name,
            'employment_type' => $employment,
            'company' => $company,
            'people_count' => max(1, count($names)),
            'crew_members' => $members,
            'active' => true,
        ]);

        return [$worker, $worker->crewPeople()->orderBy('sort_order')->get()->keyBy('name')];
    }

    private function solo(string $name, ?string $company = null): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => $company === null ? 'eigen' : 'zzp',
            'company' => $company,
            'people_count' => 1,
            'active' => true,
        ]);
    }

    private function work(string $name): WorkItem
    {
        $customer = Customer::query()->firstOrCreate(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '26'.str_pad((string) (Project::query()->count() + 1), 6, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'name' => $name,
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);

        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum '.$name,
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
    }

    /**
     * @param  list<int>  $crewIds
     */
    private function place(
        Worker $worker,
        WorkItem $work,
        string $date,
        array $crewIds = [],
        string $from = '08:00:00',
        string $to = '16:00:00',
    ): WorkerAssignment {
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $work->project_id,
            'work_item_id' => $work->id,
            'people_count' => max(1, count($crewIds)),
        ]);
        $assignment->applySchedule(Carbon::parse($date), Carbon::parse($date), $from, $to);
        $assignment->save();
        if ($crewIds !== []) {
            $assignment->syncPresentCrew($crewIds);
        }

        return $assignment->fresh();
    }

    /**
     * @return list<array{id: int, project_id: int, start_date: string, end_date: string}>
     */
    private function assignmentSnapshot(): array
    {
        return WorkerAssignment::query()
            ->orderBy('id')
            ->get()
            ->map(fn (WorkerAssignment $assignment): array => [
                'id' => $assignment->id,
                'project_id' => (int) $assignment->project_id,
                'start_date' => $assignment->start_date->toDateString(),
                'end_date' => $assignment->end_date->toDateString(),
            ])
            ->all();
    }
}
