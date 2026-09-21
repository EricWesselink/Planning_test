<?php

namespace Tests\Feature;

use App\Enums\AvailabilityKind;
use App\Enums\LeaveRequestStatus;
use App\Models\Customer;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkerAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_lets_a_planner_mark_friday_off_for_own_staff(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter', 'eigen');

        $this->actingAs($user)
            ->from(route('personnel.index'))
            ->patch(route('workers.friday.update', $peter), [
                'friday_off' => '1',
            ])
            ->assertRedirect(route('personnel.index'));

        $this->assertTrue($peter->fresh()->friday_off);
        $this->assertFalse($peter->fresh()->crewPeople->first()->worksOn(5));

        $this->actingAs($user)
            ->get(route('personnel.index'))
            ->assertOk()
            ->assertSee('Personeel')
            ->assertSee('Peter');

        $this->actingAs($user)
            ->get(route('personnel.index'))
            ->assertOk()
            ->assertDontSee('Vrij op vrijdag');

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertDontSee('Vrij op vrijdag');
    }

    public function test_absence_tab_lists_only_own_staff(): void
    {
        $user = User::factory()->create();
        $this->makeWorker('Peter', 'eigen');
        $this->makeWorker('Nick Seine', 'zzp');

        $this->actingAs($user)
            ->get(route('personnel.index'))
            ->assertOk()
            ->assertSee('Afwezigheid')
            ->assertSee('Personeel')
            ->assertSee(route('personnel.index'), false)
            ->assertSee('Peter')
            ->assertDontSee('Nick Seine')
            ->assertDontSee('Vrij op vrijdag');

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Peter')
            ->assertSee('Nick Seine');
    }

    public function test_own_employee_page_hides_availability_and_shows_the_absence_tab(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter', 'eigen');

        $this->actingAs($user)
            ->get(route('workers.show', $peter))
            ->assertOk()
            ->assertSee('Personeel')
            ->assertSee(route('personnel.index'), false)
            ->assertDontSee('Helemaal niet beschikbaar')
            ->assertDontSee('Vrij op vrijdag');
    }

    public function test_absence_overview_lists_each_person_of_a_team(): void
    {
        $user = User::factory()->create();
        $team = $this->makeWorker('Team Wespro', 'eigen');
        $team->update([
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Eric Wesselink', 'phone' => '0611111111'],
                ['name' => 'Harm Wesselink', 'phone' => '0622222222'],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('personnel.index', ['tab' => 'afwezigheid']))
            ->assertOk()
            ->assertSee('Eric Wesselink')
            ->assertSee('Harm Wesselink')
            ->assertSee('name="crew_member_id"', false)
            ->assertDontSee('Vrij op vrijdag');
    }

    public function test_planner_can_mark_one_person_friday_off_without_the_other(): void
    {
        $user = User::factory()->create();
        $team = $this->makeWorker('Team Wespro', 'eigen');
        $team->update([
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Eric Wesselink', 'phone' => '0611111111'],
                ['name' => 'Harm Wesselink', 'phone' => '0622222222'],
            ],
        ]);
        $eric = $team->fresh()->crewPeople->firstWhere('name', 'Eric Wesselink');
        $harm = $team->fresh()->crewPeople->firstWhere('name', 'Harm Wesselink');

        $this->actingAs($user)
            ->from(route('personnel.index'))
            ->patch(route('workers.friday.update', $team), [
                'crew_member_id' => $eric->id,
                'friday_off' => '1',
            ])
            ->assertRedirect(route('personnel.index'));

        $this->assertTrue($eric->fresh()->friday_off);
        $this->assertFalse($harm->fresh()->friday_off);
        $this->assertFalse($team->fresh()->friday_off);
    }

    public function test_rejects_friday_off_for_a_zzp(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');

        $this->actingAs($user)
            ->patch(route('workers.friday.update', $nick), [
                'friday_off' => '1',
            ])
            ->assertForbidden();

        $this->assertFalse($nick->fresh()->friday_off);
    }

    public function test_overview_lets_a_planner_set_zzp_unavailable_and_available_periods(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.availability.store', $nick), [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-12',
                'kind' => AvailabilityKind::Unavailable->value,
            ])
            ->assertRedirect(route('workers.index'));

        $window = WorkerAvailability::query()->where('worker_id', $nick->id)->first();
        $this->assertNotNull($window);
        $this->assertSame('2026-09-07', $window->start_date->toDateString());
        $this->assertSame('2026-09-12', $window->end_date->toDateString());
        $this->assertSame(AvailabilityKind::Unavailable, $window->kind);

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.availability.store', $nick), [
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-18',
                'kind' => AvailabilityKind::Available->value,
            ])
            ->assertRedirect(route('workers.index'));

        $html = $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Niet 7 sep')
            ->assertSee('Wel 14 sep')
            ->getContent();

        $this->assertStringNotContainsString('Vrij op vrijdag', $html);
    }

    #[DataProvider('employmentTypes')]
    public function test_overview_lets_a_planner_switch_a_team_fully_unavailable(string $type, string $name): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker($name, $type);

        $overview = $this->availabilityOverview($type);

        $this->actingAs($user)
            ->from($overview)
            ->patch(route('workers.availability.update', $worker), [
                'unavailable' => '1',
            ])
            ->assertRedirect($overview)
            ->assertSessionHas('status', 'Staat nu helemaal niet beschikbaar.');

        $this->assertTrue($worker->fresh()->unavailable);

        $html = $this->actingAs($user)
            ->get($overview)
            ->assertOk()
            ->assertSee('text-nicon-danger">Niet beschikbaar</span>', false)
            ->getContent();

        if ($type === 'eigen') {
            $this->assertStringNotContainsString('Helemaal niet beschikbaar', $html);
        } else {
            $this->assertStringContainsString('Helemaal niet beschikbaar', $html);
            $this->assertMatchesRegularExpression(
                '/name="unavailable"[^>]*checked/',
                $html,
            );
        }

        $this->actingAs($user)
            ->from($overview)
            ->patch(route('workers.availability.update', $worker), [
                'unavailable' => '0',
            ])
            ->assertRedirect($overview)
            ->assertSessionHas('status', 'Staat weer beschikbaar.');

        $this->assertFalse($worker->fresh()->unavailable);
    }

    public function test_editing_the_team_does_not_clear_the_unavailable_switch(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker('Peter', 'eigen');
        $worker->update(['unavailable' => true]);

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), [
                'name' => 'Peter',
                'employment_type' => 'eigen',
                'active' => '1',
            ])
            ->assertRedirect(route('workers.show', $worker));

        $this->assertTrue($worker->fresh()->unavailable);
    }

    public function test_planner_can_remove_an_availability_period(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $window = $nick->availabilities()->create([
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-12',
            'kind' => AvailabilityKind::Unavailable,
        ]);
        $kept = $nick->availabilities()->create([
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'kind' => AvailabilityKind::Available,
        ]);
        $leave = LeaveRequest::factory()->approved()->create([
            'user_id' => $nick->user->id,
            'worker_id' => $nick->id,
            'worker_availability_id' => $kept->id,
            'reviewed_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->delete(route('workers.availability.destroy', [$nick, $window]))
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('status', 'Periode verwijderd.');

        $this->assertDatabaseMissing('worker_availabilities', [
            'id' => $window->id,
        ]);
        $this->assertModelExists($kept);
        $leave->refresh();
        $this->assertSame(LeaveRequestStatus::Approved, $leave->status);
        $this->assertSame($kept->id, $leave->worker_availability_id);
    }

    public function test_removing_another_workers_availability_period_returns_not_found(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $peter = $this->makeWorker('Peter', 'eigen');
        $window = $peter->availabilities()->create([
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-12',
            'kind' => AvailabilityKind::Unavailable,
        ]);

        $this->actingAs($user)
            ->delete(route('workers.availability.destroy', [$nick, $window]))
            ->assertNotFound();

        $this->assertModelExists($window);
    }

    public function test_uitvoerder_cannot_remove_an_availability_period(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $window = $nick->availabilities()->create([
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-12',
            'kind' => AvailabilityKind::Unavailable,
        ]);

        $this->actingAs($user)
            ->delete(route('workers.availability.destroy', [$nick, $window]))
            ->assertForbidden();

        $this->assertModelExists($window);
    }

    public function test_rejects_an_end_date_before_the_start_date(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.availability.store', $nick), [
                'start_date' => '2026-09-12',
                'end_date' => '2026-09-07',
                'kind' => AvailabilityKind::Unavailable->value,
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasErrors('end_date');

        $this->assertSame(0, WorkerAvailability::query()->count());
    }

    public function test_uitvoerder_cannot_change_availability(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $peter = $this->makeWorker('Peter', 'eigen');

        $this->actingAs($user)
            ->patch(route('workers.friday.update', $peter), [
                'friday_off' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('workers.availability.update', $peter), [
                'unavailable' => '1',
            ])
            ->assertForbidden();

        $fresh = $peter->fresh();
        $this->assertFalse($fresh->friday_off);
        $this->assertFalse($fresh->unavailable);
    }

    public function test_unauthenticated_availability_update_redirects_to_login(): void
    {
        $peter = $this->makeWorker('Peter', 'eigen');

        $this->patch(route('workers.friday.update', $peter), [
            'friday_off' => '1',
        ])->assertRedirect(route('login'));

        $this->patch(route('workers.availability.update', $peter), [
            'unavailable' => '1',
        ])->assertRedirect(route('login'));

        $this->get(route('personnel.index'))->assertRedirect(route('login'));
    }

    public function test_planning_candidates_mark_friday_off_as_unavailable(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter', 'eigen');
        $peter->update(['friday_off' => true, 'specialty' => 'Linoleum']);
        $item = $this->makeWorkItem('Linoleum');

        $this->actingAs($user)
            ->getJson(route('planning.candidates', [
                'work_item_id' => $item->id,
                'start_date' => '2026-09-11',
                'end_date' => '2026-09-11',
                'start_time' => '08:00',
                'end_time' => '16:00',
            ]))
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Peter',
                'selectable' => false,
                'status_label' => 'Vrij op vrijdag',
            ]);
    }

    public function test_rejects_scheduling_when_the_zzp_is_unavailable(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $nick->update(['specialty' => 'Linoleum']);
        $nick->availabilities()->create([
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-12',
            'kind' => AvailabilityKind::Unavailable,
        ]);
        $item = $this->makeWorkItem('Linoleum');

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $nick->id,
                'project_id' => $item->project_id,
                'work_item_id' => $item->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nick Seine is niet beschikbaar.');

        $this->assertDatabaseCount('worker_assignments', 0);
    }

    public function test_rejects_scheduling_when_the_team_is_switched_fully_unavailable(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $nick->update(['specialty' => 'Linoleum', 'unavailable' => true]);
        $item = $this->makeWorkItem('Linoleum');

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $nick->id,
                'project_id' => $item->project_id,
                'work_item_id' => $item->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nick Seine is niet beschikbaar.');

        $this->assertDatabaseCount('worker_assignments', 0);
    }

    public function test_planning_candidates_mark_a_fully_unavailable_team(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter', 'eigen');
        $peter->update(['unavailable' => true, 'specialty' => 'Linoleum']);
        $item = $this->makeWorkItem('Linoleum');

        $this->actingAs($user)
            ->getJson(route('planning.candidates', [
                'work_item_id' => $item->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '16:00',
            ]))
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Peter',
                'selectable' => false,
                'status_label' => 'Niet beschikbaar',
            ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function employmentTypes(): array
    {
        return [
            'eigen' => ['eigen', 'Peter'],
            'zzp' => ['zzp', 'Nick Seine'],
        ];
    }

    private function availabilityOverview(string $type): string
    {
        return $type === 'eigen' ? route('personnel.index') : route('workers.index');
    }

    private function makeWorker(string $name, string $type): Worker
    {
        $worker = Worker::query()->create([
            'name' => $name,
            'employment_type' => $type,
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        User::factory()->vakman($worker->id)->create(['name' => $name]);

        return $worker;
    }

    private function makeWorkItem(string $name): WorkItem
    {
        $customer = Customer::query()->create(['name' => 'Gemeente']);
        $project = Project::query()->create([
            'project_number' => '260200090',
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
}
