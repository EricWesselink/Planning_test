<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Project;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\ConflictService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ConflictServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_team_across_onderdelen_is_not_double_booked(): void
    {
        [$worker, $egaliseren, $linoleum] = $this->makeTeamWithTwoWorkItems(2);
        $this->assign($worker, $egaliseren, 1);
        $this->assign($worker, $linoleum, 1);

        $map = app(ConflictService::class)->doubleBookedMap(
            WorkerAssignment::query()->with('worker')->get(),
            $this->days(),
        );

        $this->assertSame([], $map);
    }

    public function test_exceeding_team_size_on_another_work_is_double_booked(): void
    {
        [$worker, $egaliseren] = $this->makeTeamWithTwoWorkItems(2);
        $this->assign($worker, $egaliseren, 2);
        $this->assign($worker, $this->otherWorkItem('School Zwolle', '260200091'), 1);

        $map = app(ConflictService::class)->doubleBookedMap(
            WorkerAssignment::query()->with('worker')->get(),
            $this->days(),
        );

        $this->assertSame(3, $map[$worker->id]['2026-09-07']['used']);
    }

    public function test_a_single_person_on_two_onderdelen_of_the_same_work_is_not_double_booked(): void
    {
        [$worker, $egaliseren, $linoleum] = $this->makeTeamWithTwoWorkItems(1);
        $this->assign($worker, $egaliseren, 1);
        $this->assign($worker, $linoleum, 1);

        $map = app(ConflictService::class)->doubleBookedMap(
            WorkerAssignment::query()->with('worker')->get(),
            $this->days(),
        );

        $this->assertSame([], $map);
    }

    public function test_capacity_conflict_is_null_when_the_second_person_fits(): void
    {
        [$worker, $egaliseren] = $this->makeTeamWithTwoWorkItems(2);
        $this->assign($worker, $egaliseren, 1);

        $conflict = app(ConflictService::class)->capacityConflict(
            $worker->id,
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            1,
        );

        $this->assertNull($conflict);
    }

    public function test_capacity_conflict_returns_used_count_when_the_team_is_full(): void
    {
        [$worker, $egaliseren] = $this->makeTeamWithTwoWorkItems(2);
        $this->assign($worker, $egaliseren, 2);

        $conflict = app(ConflictService::class)->capacityConflict(
            $worker->id,
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            1,
        );

        $this->assertNotNull($conflict);
        $this->assertSame(3, $conflict['used']);
        $this->assertSame(2, $conflict['capacity']);
        $this->assertSame($worker->id, $conflict['worker']->id);
        $this->assertCount(1, $conflict['overlaps']);
    }

    public function test_named_people_on_two_onderdelen_are_not_double_booked(): void
    {
        [$worker, $egaliseren, $linoleum] = $this->makeTeamWithTwoWorkItems(3, ['Piet', 'Kees', 'Jan']);
        $people = $worker->crewPeople()->orderBy('sort_order')->get();
        $this->assignNamed($worker, $egaliseren, [$people[0]->id, $people[1]->id]);
        $this->assignNamed($worker, $linoleum, [$people[2]->id]);

        $map = app(ConflictService::class)->doubleBookedMap(
            WorkerAssignment::query()->with(['worker', 'crewMembers'])->get(),
            $this->days(),
        );

        $this->assertSame([], $map);
    }

    public function test_the_same_named_person_on_two_onderdelen_of_the_same_work_is_not_double_booked(): void
    {
        [$worker, $egaliseren, $linoleum] = $this->makeTeamWithTwoWorkItems(3, ['Piet', 'Kees', 'Jan']);
        $piet = $worker->crewPeople()->where('name', 'Piet')->first();
        $kees = $worker->crewPeople()->where('name', 'Kees')->first();
        $this->assignNamed($worker, $egaliseren, [$piet->id, $kees->id]);
        $this->assignNamed($worker, $linoleum, [$piet->id]);

        $map = app(ConflictService::class)->doubleBookedMap(
            WorkerAssignment::query()->with(['worker', 'crewMembers'])->get(),
            $this->days(),
        );

        $this->assertSame([], $map);
    }

    public function test_the_same_named_person_on_two_works_is_double_booked(): void
    {
        [$worker, $egaliseren] = $this->makeTeamWithTwoWorkItems(3, ['Piet', 'Kees', 'Jan']);
        $piet = $worker->crewPeople()->where('name', 'Piet')->first();
        $this->assignNamed($worker, $egaliseren, [$piet->id]);
        $this->assignNamed($worker, $this->otherWorkItem('School Zwolle', '260200091'), [$piet->id]);

        $map = app(ConflictService::class)->doubleBookedMap(
            WorkerAssignment::query()->with(['worker', 'crewMembers'])->get(),
            $this->days(),
        );

        $this->assertSame(['Piet'], $map[$worker->id]['2026-09-07']['person_names']);
    }

    public function test_capacity_conflict_names_the_person_already_planned(): void
    {
        [$worker, $egaliseren] = $this->makeTeamWithTwoWorkItems(3, ['Piet', 'Kees', 'Jan']);
        $piet = $worker->crewPeople()->where('name', 'Piet')->first();
        $kees = $worker->crewPeople()->where('name', 'Kees')->first();
        $this->assignNamed($worker, $egaliseren, [$piet->id, $kees->id]);

        $conflict = app(ConflictService::class)->capacityConflict(
            $worker->id,
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            1,
            null,
            [$piet->id],
        );

        $this->assertNotNull($conflict);
        $this->assertSame('Piet', $conflict['person']);
    }

    public function test_morning_and_afternoon_for_the_same_person_is_not_a_conflict(): void
    {
        [$worker, $egaliseren, $linoleum] = $this->makeTeamWithTwoWorkItems(1);
        $this->assign($worker, $egaliseren, 1, '08:00:00', '12:00:00');

        $conflict = app(ConflictService::class)->capacityConflict(
            $worker->id,
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            1,
            null,
            [],
            '12:00',
            '16:00',
        );

        $this->assertNull($conflict);

        $this->assign($worker, $linoleum, 1, '12:00:00', '16:00:00');
        $map = app(ConflictService::class)->doubleBookedMap(
            WorkerAssignment::query()->with(['worker', 'crewMembers'])->get(),
            $this->days(),
        );

        $this->assertSame([], $map);
    }

    public function test_overlapping_hours_for_the_same_person_are_a_conflict(): void
    {
        [$worker, $egaliseren] = $this->makeTeamWithTwoWorkItems(1);
        $this->assign($worker, $egaliseren, 1, '08:00:00', '16:00:00');

        $conflict = app(ConflictService::class)->capacityConflict(
            $worker->id,
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            1,
            null,
            [],
            '12:00',
            '16:00',
        );

        $this->assertNotNull($conflict);
        $this->assertSame(2, $conflict['used']);
    }

    /**
     * @param  list<string>  $names
     * @return array{0: Worker, 1: WorkItem, 2: WorkItem}
     */
    private function makeTeamWithTwoWorkItems(int $people, array $names = []): array
    {
        $members = [];
        foreach ($names as $name) {
            $members[] = ['name' => $name, 'phone' => ''];
        }

        $worker = Worker::query()->create([
            'name' => 'Jansen Vloeren',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'people_count' => $people,
            'crew_members' => $members !== [] ? $members : null,
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-08',
        ]);
        $egaliseren = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 3440,
            'status' => 'in_uitvoering',
        ]);
        $linoleum = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 3168,
            'status' => 'in_uitvoering',
        ]);

        return [$worker, $egaliseren, $linoleum];
    }

    private function otherWorkItem(string $projectName, string $projectNumber): WorkItem
    {
        $customer = Customer::query()->create(['name' => $projectName]);
        $project = Project::query()->create([
            'project_number' => $projectNumber,
            'customer_id' => $customer->id,
            'name' => $projectName,
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-08',
        ]);

        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 80,
            'status' => 'in_uitvoering',
        ]);
    }

    private function assign(Worker $worker, WorkItem $item, int $people, string $startTime = '08:00:00', string $endTime = '16:00:00'): WorkerAssignment
    {
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $item->project_id,
            'work_item_id' => $item->id,
            'people_count' => $people,
        ]);
        $assignment->applySchedule(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            $startTime,
            $endTime,
        );
        $assignment->save();

        return $assignment;
    }

    /**
     * @param  list<int>  $crewMemberIds
     */
    private function assignNamed(Worker $worker, WorkItem $item, array $crewMemberIds): WorkerAssignment
    {
        $assignment = $this->assign($worker, $item, count($crewMemberIds));
        $assignment->syncPresentCrew($crewMemberIds);

        return $assignment->fresh(['crewMembers']);
    }

    /**
     * @return Collection<int, Carbon>
     */
    private function days(): Collection
    {
        return collect([
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-08'),
        ]);
    }
}
