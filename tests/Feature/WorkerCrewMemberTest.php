<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerCrewMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_form_shows_active_and_delete_for_each_person(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam();

        $this->actingAs($user)
            ->get(route('workers.show', $team))
            ->assertOk()
            ->assertSee('Persoon 1')
            ->assertSee('Persoon 3')
            ->assertSee('3 personen')
            ->assertSee('+ Persoon toevoegen')
            ->assertSee('Actief')
            ->assertSee('wordt verwijderd uit het team')
            ->assertDontSee('name="people_count"', false);
    }

    public function test_planner_can_set_a_teammate_inactive(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam();
        $mohammed = $team->crewPeople->firstWhere('name', 'Mohammed');

        $this->actingAs($user)
            ->from(route('workers.show', $team))
            ->patch(route('workers.crew-members.active.update', [$team, $mohammed]), [
                'active' => '0',
            ])
            ->assertRedirect(route('workers.show', $team))
            ->assertSessionHas('status', 'Mohammed is inactief gezet.');

        $this->assertFalse($mohammed->fresh()->active);
        $this->assertSame(2, $team->fresh('crewPeople')->peopleCount());
        $this->assertSame(3, $team->fresh('crewPeople')->rosterCount());
    }

    public function test_planner_can_reactivate_a_teammate(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam();
        $mohammed = $team->crewPeople->firstWhere('name', 'Mohammed');
        $mohammed->update(['active' => false]);

        $this->actingAs($user)
            ->from(route('workers.show', $team))
            ->patch(route('workers.crew-members.active.update', [$team, $mohammed]), [
                'active' => '1',
            ])
            ->assertRedirect(route('workers.show', $team))
            ->assertSessionHas('status', 'Mohammed is weer actief.');

        $this->assertTrue($mohammed->fresh()->active);
        $this->assertSame(3, $team->fresh('crewPeople')->peopleCount());
    }

    public function test_planner_can_delete_a_teammate(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam();
        $mohammed = $team->crewPeople->firstWhere('name', 'Mohammed');

        $this->actingAs($user)
            ->from(route('workers.show', $team))
            ->delete(route('workers.crew-members.destroy', [$team, $mohammed]))
            ->assertRedirect(route('workers.show', $team))
            ->assertSessionHas('status', 'Mohammed is verwijderd.');

        $this->assertModelMissing($mohammed);
        $team = $team->fresh('crewPeople');
        $this->assertSame(['Nick', 'Mahmoud'], $team->crewPeople->pluck('name')->all());
        $this->assertSame(2, $team->peopleCount());
        $this->assertSame(2, $team->rosterCount());
    }

    public function test_refuses_to_delete_the_last_teammate(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter');
        $member = $peter->fresh()->crewPeople->first();

        $this->actingAs($user)
            ->from(route('workers.show', $peter))
            ->delete(route('workers.crew-members.destroy', [$peter, $member]))
            ->assertRedirect(route('workers.show', $peter))
            ->assertSessionHasErrors(['worker' => 'Peter is de laatste in het team. Zet hem inactief in plaats van te verwijderen.']);

        $this->assertModelExists($member);
    }

    public function test_rejects_a_teammate_change_from_another_team(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam();
        $other = $this->makeWorker('Kees');
        $member = $other->fresh()->crewPeople->first();

        $this->actingAs($user)
            ->patch(route('workers.crew-members.active.update', [$team, $member]), [
                'active' => '0',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->delete(route('workers.crew-members.destroy', [$team, $member]))
            ->assertNotFound();

        $this->assertTrue($member->fresh()->active);
        $this->assertModelExists($member);
    }

    public function test_uitvoerder_cannot_deactivate_or_delete_a_teammate(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $team = $this->makeTeam();
        $mohammed = $team->crewPeople->firstWhere('name', 'Mohammed');

        $this->actingAs($user)
            ->patch(route('workers.crew-members.active.update', [$team, $mohammed]), [
                'active' => '0',
            ])
            ->assertForbidden();
        $this->actingAs($user)
            ->delete(route('workers.crew-members.destroy', [$team, $mohammed]))
            ->assertForbidden();

        $this->assertTrue($mohammed->fresh()->active);
        $this->assertModelExists($mohammed);
    }

    public function test_unauthenticated_teammate_request_redirects_to_login(): void
    {
        $team = $this->makeTeam();
        $mohammed = $team->crewPeople->firstWhere('name', 'Mohammed');

        $this->patch(route('workers.crew-members.active.update', [$team, $mohammed]), [
            'active' => '0',
        ])->assertRedirect(route('login'));
        $this->delete(route('workers.crew-members.destroy', [$team, $mohammed]))
            ->assertRedirect(route('login'));
    }

    public function test_deactivating_a_teammate_turns_off_their_login(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam();
        $mohammed = $team->crewPeople->firstWhere('name', 'Mohammed');
        $login = User::factory()->vakman($team->id)->create([
            'name' => 'Mohammed',
            'crew_member_id' => $mohammed->id,
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('workers.crew-members.active.update', [$team, $mohammed]), [
                'active' => '0',
            ])
            ->assertRedirect();

        $this->assertFalse($login->fresh()->active);
    }

    public function test_adding_a_person_without_a_count_field_raises_the_total(): void
    {
        $user = User::factory()->create();
        $team = $this->makePair();
        $members = $team->crewPeople->map(fn ($member): array => [
            'id' => $member->id,
            'name' => $member->name,
            'phone' => $member->phone,
            'active' => '1',
            'registers_hours' => '1',
        ])->all();
        $members[] = [
            'name' => 'Piet',
            'phone' => '0611111111',
            'active' => '1',
            'registers_hours' => '1',
        ];

        $this->actingAs($user)
            ->patch(route('workers.update', $team), [
                'name' => 'Team 1',
                'employment_type' => 'eigen',
                'active' => '1',
                'crew_members' => $members,
            ])
            ->assertRedirect(route('workers.show', $team));

        $team = $team->fresh('crewPeople');
        $this->assertSame(['Willem', 'Jan', 'Piet'], $team->crewPeople->pluck('name')->all());
        $this->assertSame(3, $team->peopleCount());
        $this->assertSame(3, $team->people_count);
    }

    public function test_removing_a_person_with_history_keeps_planning_and_hours(): void
    {
        $user = User::factory()->create();
        $team = $this->makePair();
        $willem = $team->crewPeople->firstWhere('name', 'Willem');
        $jan = $team->crewPeople->firstWhere('name', 'Jan');
        $login = User::factory()->vakman($team->id, $jan->id)->create([
            'name' => 'Jan',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Griftland']);
        $project = Project::query()->create([
            'project_number' => 'TF29047',
            'customer_id' => $customer->id,
            'name' => 'Griftland college',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-11',
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'in_uitvoering',
        ]);
        $both = WorkerAssignment::query()->create([
            'worker_id' => $team->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-07',
            'hours_per_day' => 8,
            'people_count' => 2,
        ]);
        $both->syncPresentCrew([$willem->id, $jan->id]);
        $entry = TimeEntry::query()->create([
            'worker_id' => $team->id,
            'crew_member_id' => $jan->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'worker_assignment_id' => $both->id,
            'date' => '2026-09-07',
            'hours' => 8,
            'identity_key' => 'jan-2026-09-07',
            'status' => 'ingediend',
        ]);

        $this->actingAs($user)
            ->delete(route('workers.crew-members.destroy', [$team, $jan]))
            ->assertRedirect();

        $this->assertSoftDeleted($jan);
        $this->assertModelExists($login);
        $this->assertFalse($login->fresh()->active);
        $this->assertSame($jan->id, $entry->fresh()->crew_member_id);
        $this->assertTrue($both->fresh()->crewMembers->contains(fn ($member): bool => (int) $member->id === (int) $jan->id));
        $team = $team->fresh('crewPeople');
        $this->assertSame(['Willem'], $team->crewPeople->pluck('name')->all());
        $this->assertSame(1, $team->peopleCount());

        $onlyWillem = WorkerAssignment::query()->create([
            'worker_id' => $team->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'hours_per_day' => 8,
            'people_count' => 1,
        ]);
        $onlyWillem->syncPresentCrew([$willem->id]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('data-label-full="T1 · Willem · Jan"', false)
            ->assertSee('data-label-full="T1 · Willem"', false);
    }

    public function test_deleting_a_teammate_removes_their_login(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam();
        $mohammed = $team->crewPeople->firstWhere('name', 'Mohammed');
        $login = User::factory()->vakman($team->id)->create([
            'name' => 'Mohammed',
            'crew_member_id' => $mohammed->id,
        ]);

        $this->actingAs($user)
            ->delete(route('workers.crew-members.destroy', [$team, $mohammed]))
            ->assertRedirect();

        $this->assertModelMissing($login);
    }

    private function makeTeam(): Worker
    {
        $worker = Worker::query()->create([
            'name' => 'Team 1 Nick',
            'employment_type' => 'eigen',
            'people_count' => 3,
            'crew_members' => [
                ['name' => 'Nick', 'phone' => '0657925505'],
                ['name' => 'Mahmoud', 'phone' => '0685035333'],
                ['name' => 'Mohammed', 'phone' => '0639332148'],
            ],
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $worker->load('crewPeople');

        return $worker;
    }

    private function makePair(): Worker
    {
        $worker = Worker::query()->create([
            'name' => 'Team 1',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Willem', 'phone' => '0628345656'],
                ['name' => 'Jan', 'phone' => '0612345678'],
            ],
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $worker->load('crewPeople');

        return $worker;
    }

    private function makeWorker(string $name): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
    }
}
