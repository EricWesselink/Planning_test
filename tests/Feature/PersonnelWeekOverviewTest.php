<?php

namespace Tests\Feature;

use App\Enums\AvailabilityKind;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class PersonnelWeekOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_personnel_week_and_pdf(): void
    {
        $this->get(route('planning.personnel-week', ['week' => '2026-09-21']))
            ->assertRedirect(route('login'));
        $this->get(route('planning.personnel-week.pdf', ['week' => '2026-09-21']))
            ->assertRedirect(route('login'));
    }

    public function test_planning_page_links_to_the_personnel_week_overview(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-21']))
            ->assertOk()
            ->assertSee('Weekplanning vakmannen')
            ->assertSee('Weekplanning personeel')
            ->assertSee(route('planning.personnel-week', ['week' => '2026-09-21'], false), false);
    }

    public function test_overview_lists_present_vakman_names_without_team_hours(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam('Team 3 Arek', [
            ['name' => 'Arek Kowalski', 'phone' => ''],
            ['name' => 'Mohammed Ali', 'phone' => ''],
            ['name' => 'Peter de Vries', 'phone' => ''],
        ]);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        [$project, $item] = $this->makeProject('Dussen - IJsselmuiden', [
            'customer' => 'Kloppenburg Interieur',
            'address' => 'Tichlerstraat 25',
            'postal_code' => '8271 VD',
            'city' => 'IJsselmuiden',
        ]);
        $assignment = $this->assign($team, $project, $item, '2026-09-21', '2026-09-23', '08:00:00', '16:00:00');
        $assignment->syncPresentCrew([$people[0]->id, $people[1]->id]);

        $this->actingAs($user)
            ->get(route('planning.personnel-week', ['week' => '2026-09-21']))
            ->assertOk()
            ->assertSee('Weekplanning personeel')
            ->assertSee('Week 39')
            ->assertSee('Kloppenburg Interieur')
            ->assertSee('Dussen - IJsselmuiden')
            ->assertSee('Tichlerstraat 25')
            ->assertSee('Primen & Egaliseren')
            ->assertSee('Arek')
            ->assertSee('Mohammed')
            ->assertSee('Weekplanning personeel PDF')
            ->assertDontSee('Peter')
            ->assertDontSee('6u')
            ->assertDontSee('€');
    }

    public function test_teammate_on_another_job_is_not_listed_on_this_work(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam('Team 2', [
            ['name' => 'Alexandr', 'phone' => ''],
            ['name' => 'José', 'phone' => ''],
            ['name' => 'Peter', 'phone' => ''],
        ]);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        [$home, $homeItem] = $this->makeProject('Laakse Tuinen');
        [$away, $awayItem] = $this->makeProject('Het Vloerenhuis', [
            'city' => 'Laren',
        ]);
        $homeAssignment = $this->assign($team, $home, $homeItem, '2026-09-22', '2026-09-22', '08:00:00', '16:00:00');
        $homeAssignment->syncPresentCrew([$people[0]->id, $people[1]->id]);
        $awayAssignment = $this->assign($team, $away, $awayItem, '2026-09-22', '2026-09-22', '08:00:00', '16:00:00');
        $awayAssignment->syncPresentCrew([$people[2]->id]);

        $this->actingAs($user)
            ->get(route('planning.personnel-week', ['week' => '2026-09-21']))
            ->assertOk()
            ->assertSee('Laakse Tuinen')
            ->assertSee('Alexandr')
            ->assertSee('José')
            ->assertSee('Het Vloerenhuis')
            ->assertSee('Peter');
    }

    public function test_part_of_the_day_shows_the_clock_times(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeTeam('Team 5', [
            ['name' => 'Peter', 'phone' => ''],
        ]);
        $person = $worker->crewPeople()->first();
        [$morning, $morningItem] = $this->makeProject('Werk A');
        [$afternoon, $afternoonItem] = $this->makeProject('Werk B');
        $this->assign($worker, $morning, $morningItem, '2026-09-21', '2026-09-21', '08:00:00', '12:00:00')
            ->syncPresentCrew([$person->id]);
        $this->assign($worker, $afternoon, $afternoonItem, '2026-09-21', '2026-09-21', '12:00:00', '16:00:00')
            ->syncPresentCrew([$person->id]);

        $this->actingAs($user)
            ->get(route('planning.personnel-week', ['week' => '2026-09-21']))
            ->assertOk()
            ->assertSee('Werk A')
            ->assertSee('Werk B')
            ->assertSee('08:00 – 12:00')
            ->assertSee('12:00 – 16:00')
            ->assertSee('Peter');
    }

    public function test_shows_start_klaar_and_vacation_without_prices(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam('Team 4', [
            ['name' => 'Sietse', 'phone' => ''],
        ]);
        $person = $team->crewPeople()->first();
        [$project, $item] = $this->makeProject('Laakse Tuinen', [
            'planned_start_date' => '2026-09-21',
            'planned_end_date' => '2026-09-23',
        ]);
        $this->assign($team, $project, $item, '2026-09-21', '2026-09-21', '08:00:00', '16:00:00')
            ->syncPresentCrew([$person->id]);
        $team->availabilities()->create([
            'crew_member_id' => $person->id,
            'start_date' => '2026-09-22',
            'end_date' => '2026-09-22',
            'kind' => AvailabilityKind::Vacation,
            'hours' => 8,
        ]);

        $html = $this->actingAs($user)
            ->get(route('planning.personnel-week', ['week' => '2026-09-21']))
            ->assertOk()
            ->assertSee('START 21-09-2026')
            ->assertSee('KLAAR 23-09-2026')
            ->assertSee('VAKANTIE')
            ->assertSee('Sietse')
            ->assertDontSee('€')
            ->getContent();

        $this->assertStringNotContainsString('uurtarief', strtolower($html));
    }

    public function test_zzp_shows_company_and_name(): void
    {
        $user = User::factory()->create();
        $zzp = Worker::query()->create([
            'name' => 'Kees Jansen',
            'company' => 'Het Vloerenhuis',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        [$project, $item] = $this->makeProject('Gezondheidscentrum Laren');
        $this->assign($zzp, $project, $item, '2026-09-21', '2026-09-23', '08:00:00', '16:00:00');

        $this->actingAs($user)
            ->get(route('planning.personnel-week', ['week' => '2026-09-21']))
            ->assertOk()
            ->assertSee('Het Vloerenhuis')
            ->assertSee('Kees');
    }

    public function test_pdf_downloads_the_personnel_week_overview(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam('Team 3 Arek', [
            ['name' => 'Arek Kowalski', 'phone' => ''],
            ['name' => 'Mohammed Ali', 'phone' => ''],
        ]);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        [$project, $item] = $this->makeProject('Dussen - IJsselmuiden', [
            'customer' => 'Kloppenburg Interieur',
        ]);
        $this->assign($team, $project, $item, '2026-09-21', '2026-09-23', '08:00:00', '16:00:00')
            ->syncPresentCrew([$people[0]->id, $people[1]->id]);

        $response = $this->actingAs($user)
            ->get(route('planning.personnel-week.pdf', ['week' => '2026-09-21']));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(
            'weekplanning-personeel-week-39-2026.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));

        $text = preg_replace('/\s+/u', ' ', (new Parser)->parseContent($response->getContent())->getText()) ?? '';
        $this->assertStringContainsString('Weekplanning personeel', $text);
        $this->assertStringContainsString('Arek', $text);
        $this->assertStringContainsString('Mohammed', $text);
        $this->assertStringNotContainsString('€', $text);
    }

    /**
     * @param  list<array{name: string, phone: string}>  $crew
     */
    private function makeTeam(string $name, array $crew): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'people_count' => count($crew),
            'crew_members' => $crew,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Project, 1: WorkItem}
     */
    private function makeProject(string $name, array $attributes = []): array
    {
        $customerName = $attributes['customer'] ?? 'Gemeente';
        unset($attributes['customer']);
        $customer = Customer::query()->where('name', $customerName)->first()
            ?? Customer::query()->create(['name' => $customerName]);

        $project = Project::query()->create(array_merge([
            'project_number' => '2026-'.fake()->unique()->numerify('###'),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-21',
            'planned_end_date' => '2026-09-26',
        ], $attributes));
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 100,
            'planned_start_date' => $project->planned_start_date,
            'planned_end_date' => $project->planned_end_date,
            'status' => 'in_uitvoering',
        ]);

        return [$project, $item];
    }

    private function assign(
        Worker $worker,
        Project $project,
        WorkItem $item,
        string $startDate,
        string $endDate,
        string $startTime,
        string $endTime,
    ): WorkerAssignment {
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'hours_per_day' => 8,
            'planned_hours' => 8,
        ]);
        $assignment->applySchedule(
            $assignment->start_date,
            $assignment->end_date,
            $startTime,
            $endTime,
        );
        $assignment->save();

        return $assignment->fresh(['crewMembers']);
    }
}
