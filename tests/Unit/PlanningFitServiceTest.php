<?php

namespace Tests\Unit;

use App\Enums\ProjectKind;
use App\Models\CrewMember;
use App\Models\Customer;
use App\Models\Project;
use App\Models\SpecialtyOption;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\PlanningFitService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanningFitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_linoleum_work_requires_linoleum_vakkennis(): void
    {
        $item = $this->makeWorkItem('Linoleum');

        $this->assertSame([
            'key' => 'linoleum',
            'label' => 'Linoleum',
        ], $item->requiredSpecialty());
    }

    public function test_pvc_work_requires_pvc_vakkennis(): void
    {
        $item = $this->makeWorkItem('PVC');

        $this->assertSame('pvc', $item->requiredSpecialty()['key']);
        $this->assertSame('PVC', $item->requiredSpecialty()['label']);
    }

    public function test_primen_egaliseren_work_requires_that_vakkennis(): void
    {
        $item = $this->makeWorkItem('Primen & egaliseren');

        $this->assertSame('primen_egaliseren', $item->requiredSpecialty()['key']);
        $this->assertSame('Primen & egaliseren', $item->requiredSpecialty()['label']);
    }

    public function test_marmoleum_work_requires_linoleum_vakkennis(): void
    {
        $item = $this->makeWorkItem('Marmoleum');

        $this->assertSame('linoleum', $item->requiredSpecialty()['key']);
    }

    #[DataProvider('shopActivitySpecialties')]
    public function test_shop_activity_work_requires_that_vakkennis(string $name, string $key, string $label): void
    {
        $item = $this->makeWorkItem($name);

        $this->assertSame([
            'key' => $key,
            'label' => $label,
        ], $item->requiredSpecialty());
    }

    public function test_rails_plaatsen_uses_the_registered_vakkennis(): void
    {
        SpecialtyOption::remember('Rails plaatsen');
        $item = $this->makeWorkItem('Rails plaatsen');

        $this->assertSame('Rails plaatsen', $item->requiredSpecialty()['label']);
    }

    public function test_lists_available_when_the_person_has_vakkennis_and_is_free(): void
    {
        $kees = $this->makeWorker('Kees Jansen', 'Linoleum');
        $item = $this->makeWorkItem('Linoleum');

        $candidate = $this->candidateNamed($item, 'Kees Jansen', $kees);

        $this->assertTrue($candidate['selectable']);
        $this->assertSame('available', $candidate['status']);
        $this->assertSame('Beschikbaar', $candidate['status_label']);
    }

    public function test_lists_geen_vakkennis_when_the_person_cannot_do_the_work(): void
    {
        $this->makeWorker('Nick Seine', 'PVC');
        $item = $this->makeWorkItem('Linoleum');

        $candidate = $this->candidateNamed($item, 'Nick Seine');

        $this->assertFalse($candidate['selectable']);
        $this->assertSame('no_skill', $candidate['status']);
        $this->assertSame('Geen vakkennis: Linoleum', $candidate['status_label']);
    }

    public function test_service_work_lists_a_free_team_without_matching_vakkennis(): void
    {
        $team = $this->makeWorker('Wopro', 'PVC', ['Jan', 'Piet']);
        $item = $this->makeWorkItem('hestel schoon maken');
        $item->project->update(['kind' => ProjectKind::Service]);

        $candidate = $this->candidateNamed($item, 'Wopro', $team);

        $this->assertTrue($candidate['selectable']);
        $this->assertSame('2/2 geschikt en beschikbaar', $candidate['status_label']);
    }

    public function test_lists_bezet_with_the_overlapping_hours(): void
    {
        $peter = $this->makeWorker('Peter', 'Linoleum');
        $item = $this->makeWorkItem('Linoleum');
        $this->assignPerson($peter, $item, '08:00:00', '12:00:00');

        $candidate = $this->candidateNamed($item, 'Peter');

        $this->assertFalse($candidate['selectable']);
        $this->assertSame('busy', $candidate['status']);
        $this->assertSame('Bezet 08:00-12:00', $candidate['status_label']);
    }

    public function test_counts_only_suitable_and_available_team_members(): void
    {
        $team = $this->makeWorker('Team Jansen', 'Linoleum', ['Piet', 'Kees', 'Jan']);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $jan = $people->firstWhere('name', 'Jan');
        $this->assertInstanceOf(CrewMember::class, $jan);
        $jan->forceFill(['specialty' => 'PVC'])->save();
        $item = $this->makeWorkItem('Linoleum');
        $this->assignPerson($team, $item, '08:00:00', '12:00:00', [$people->firstWhere('name', 'Kees')->id]);

        $candidate = $this->candidateNamed($item, 'Team Jansen');

        $this->assertTrue($candidate['selectable']);
        $this->assertSame('1/3 geschikt en beschikbaar', $candidate['status_label']);
        $this->assertSame(1, $candidate['suitable_count']);
        $crew = collect($candidate['crew'])->keyBy('name');
        $this->assertTrue($crew['Piet']['selectable']);
        $this->assertSame('Beschikbaar', $crew['Piet']['status_label']);
        $this->assertFalse($crew['Kees']['selectable']);
        $this->assertSame('Bezet 08:00-12:00', $crew['Kees']['status_label']);
        $this->assertFalse($crew['Jan']['selectable']);
        $this->assertSame('Geen vakkennis: Linoleum', $crew['Jan']['status_label']);
    }

    public function test_rejects_a_person_without_vakkennis(): void
    {
        $nick = $this->makeWorker('Nick Seine', 'PVC');
        $item = $this->makeWorkItem('Linoleum');

        $message = app(PlanningFitService::class)->skillRejection($nick, $item);

        $this->assertSame('Nick Seine heeft geen vakkennis voor Linoleum.', $message);
    }

    public function test_rejects_a_named_teammate_without_vakkennis(): void
    {
        $team = $this->makeWorker('Team Jansen', 'Linoleum', ['Piet', 'Jan']);
        $jan = $team->crewPeople()->where('name', 'Jan')->first();
        $this->assertInstanceOf(CrewMember::class, $jan);
        $jan->forceFill(['specialty' => 'PVC'])->save();
        $item = $this->makeWorkItem('Linoleum');

        $message = app(PlanningFitService::class)->skillRejection($team->fresh('crewPeople'), $item, [$jan->id]);

        $this->assertSame('Jan heeft geen vakkennis voor Linoleum.', $message);
    }

    public function test_includes_a_team_without_a_login(): void
    {
        $this->makeWorker('Wepro', 'Linoleum');
        Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'eigen',
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $item = $this->makeWorkItem('Linoleum');

        $payload = app(PlanningFitService::class)->candidates(
            $item,
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            '08:00:00',
            '16:00:00',
        );

        $this->assertSame(['Kees Jansen', 'Wepro'], collect($payload['workers'])->pluck('name')->all());
    }

    public function test_winkelwerk_skips_skill_match(): void
    {
        $item = $this->makeWorkItem('Screens');
        $item->project->forceFill(['kind' => ProjectKind::Winkel])->save();

        $this->assertTrue($item->fresh('project')->skipsSkillMatch());
    }

    public function test_shop_candidates_mark_a_busy_vakman_unavailable(): void
    {
        $kees = $this->makeWorker('Kees Jansen', 'PVC');
        $item = $this->makeWorkItem('PVC');
        $this->assignPerson($kees, $item, '08:00:00', '16:00:00');

        $row = collect(app(PlanningFitService::class)->shopCandidates(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
        ))->firstWhere('name', 'Kees Jansen');

        $this->assertIsArray($row);
        $this->assertFalse($row['selectable']);
        $this->assertSame('Bezet 08:00-16:00', $row['status_label']);
    }

    public function test_shop_candidates_ignore_assignments_on_the_current_winkel_project(): void
    {
        $kees = $this->makeWorker('Kees Jansen', 'PVC');
        $item = $this->makeWorkItem('PVC');
        $this->assignPerson($kees, $item, '08:00:00', '16:00:00');

        $row = collect(app(PlanningFitService::class)->shopCandidates(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            (int) $item->project_id,
        ))->firstWhere('name', 'Kees Jansen');

        $this->assertIsArray($row);
        $this->assertTrue($row['selectable']);
    }

    /**
     * @param  list<string>  $names
     */
    private function makeWorker(string $name, string $specialty, array $names = []): Worker
    {
        $members = [];
        foreach ($names as $member) {
            $members[] = ['name' => $member, 'phone' => ''];
        }

        $worker = Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'people_count' => $names === [] ? 1 : count($names),
            'crew_members' => $members !== [] ? $members : null,
            'specialty' => $specialty,
            'active' => true,
        ]);
        User::factory()->vakman($worker->id)->create(['name' => $name]);

        return $worker;
    }

    private function makeWorkItem(string $name): WorkItem
    {
        $customer = Customer::query()->create(['name' => 'Gemeente']);
        $project = Project::query()->create([
            'project_number' => '2602000'.fake()->unique()->numerify('##'),
            'customer_id' => $customer->id,
            'name' => 'Testwerk',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ]);

        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
    }

    /**
     * @param  list<int>  $crewIds
     */
    private function assignPerson(Worker $worker, WorkItem $item, string $start, string $end, array $crewIds = []): WorkerAssignment
    {
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $item->project_id,
            'work_item_id' => $item->id,
            'people_count' => max(1, count($crewIds) ?: 1),
        ]);
        $assignment->applySchedule(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            $start,
            $end,
        );
        $assignment->save();
        if ($crewIds !== []) {
            $assignment->syncPresentCrew($crewIds);
        }

        return $assignment;
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateNamed(WorkItem $item, string $name, ?Worker $worker = null): array
    {
        $payload = app(PlanningFitService::class)->candidates(
            $item,
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            '08:00:00',
            '16:00:00',
        );
        $candidate = collect($payload['workers'])->firstWhere('name', $name);
        $this->assertIsArray($candidate);

        if ($worker) {
            $this->assertSame($worker->id, $candidate['id']);
        }

        return $candidate;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function shopActivitySpecialties(): array
    {
        return [
            'inmeten' => ['Inmeten', 'inmeten', 'Inmeten'],
            'montage' => ['Montage', 'montage', 'Montage'],
            'reparatie' => ['Reparatie', 'reparatie', 'Reparatie'],
            'service' => ['Service', 'service', 'Service'],
        ];
    }
}
