<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
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
            ->assertSee('Naam persoon 1')
            ->assertSee('Naam persoon 3')
            ->assertSee('Actief')
            ->assertSee('wordt verwijderd uit het team');
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
