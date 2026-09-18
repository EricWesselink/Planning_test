<?php

namespace Tests\Feature;

use App\Enums\AvailabilityKind;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerPersonnelTest extends TestCase
{
    use RefreshDatabase;

    public function test_personnel_tab_lists_each_own_employee_and_excludes_zzp(): void
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
        $this->makeWorker('Nick Seine', 'zzp');

        $this->actingAs($user)
            ->get(route('workers.personnel'))
            ->assertOk()
            ->assertSee('Teams')
            ->assertSee('Personeel')
            ->assertSee('Afwezigheid')
            ->assertSee('Eric Wesselink')
            ->assertSee('Harm Wesselink')
            ->assertSee('>Ma</th>', false)
            ->assertSee('>Za</th>', false)
            ->assertDontSee('Nick Seine')
            ->assertDontSee('Vrij op vrijdag');
    }

    public function test_planner_can_turn_off_a_fixed_workday_and_it_saves_immediately(): void
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
            ->from(route('workers.personnel'))
            ->patch(route('workers.personnel.update', [$team, $eric]), [
                'day' => 5,
                'works' => '0',
            ])
            ->assertRedirect(route('workers.personnel'))
            ->assertSessionHas('status', 'Vr staat als vaste vrije dag.');

        $eric = $eric->fresh();
        $harm = $harm->fresh();
        $this->assertSame([1, 2, 3, 4], $eric->workDays());
        $this->assertFalse($eric->worksOn(5));
        $this->assertTrue($eric->friday_off);
        $this->assertTrue($harm->worksOn(5));
        $this->assertFalse($harm->friday_off);
        $this->assertFalse($team->fresh()->friday_off);
        $this->assertSame(2, $team->fresh()->crewPeople()->count());
    }

    public function test_rejects_a_workday_change_for_a_zzp(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $member = $nick->fresh()->crewPeople->first();

        $this->actingAs($user)
            ->patch(route('workers.personnel.update', [$nick, $member]), [
                'day' => 5,
                'works' => '0',
            ])
            ->assertForbidden();

        $this->assertTrue($member->fresh()->worksOn(5));
    }

    public function test_rejects_a_workday_change_for_a_person_from_another_team(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter', 'eigen');
        $other = $this->makeWorker('Kees', 'eigen');
        $member = $other->fresh()->crewPeople->first();

        $this->actingAs($user)
            ->patch(route('workers.personnel.update', [$peter, $member]), [
                'day' => 5,
                'works' => '0',
            ])
            ->assertNotFound();

        $this->assertTrue($member->fresh()->worksOn(5));
    }

    public function test_rejects_an_invalid_weekday(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter', 'eigen');
        $member = $peter->fresh()->crewPeople->first();

        $this->actingAs($user)
            ->from(route('workers.personnel'))
            ->patch(route('workers.personnel.update', [$peter, $member]), [
                'day' => 7,
                'works' => '1',
            ])
            ->assertRedirect(route('workers.personnel'))
            ->assertSessionHasErrors('day');
    }

    public function test_absence_can_store_vacation_for_one_person(): void
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

        $this->actingAs($user)
            ->from(route('workers.absence'))
            ->post(route('workers.availability.store', $team), [
                'crew_member_id' => $eric->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'kind' => AvailabilityKind::Vacation->value,
            ])
            ->assertRedirect(route('workers.absence'))
            ->assertSessionHas('status', 'Vakantie opgeslagen.');

        $this->assertDatabaseHas('worker_availabilities', [
            'worker_id' => $team->id,
            'crew_member_id' => $eric->id,
            'kind' => AvailabilityKind::Vacation->value,
        ]);
    }

    public function test_uitvoerder_cannot_change_work_days(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $peter = $this->makeWorker('Peter', 'eigen');
        $member = $peter->fresh()->crewPeople->first();

        $this->actingAs($user)
            ->patch(route('workers.personnel.update', [$peter, $member]), [
                'day' => 5,
                'works' => '0',
            ])
            ->assertForbidden();

        $this->assertTrue($member->fresh()->worksOn(5));
    }

    public function test_unauthenticated_personnel_request_redirects_to_login(): void
    {
        $peter = $this->makeWorker('Peter', 'eigen');
        $member = $peter->fresh()->crewPeople->first();

        $this->get(route('workers.personnel'))->assertRedirect(route('login'));
        $this->patch(route('workers.personnel.update', [$peter, $member]), [
            'day' => 5,
            'works' => '0',
        ])->assertRedirect(route('login'));
    }

    public function test_vakman_cannot_open_personnel(): void
    {
        $peter = $this->makeWorker('Peter', 'eigen');
        $user = User::factory()->vakman($peter->id)->create();

        $this->actingAs($user)
            ->get(route('workers.personnel'))
            ->assertForbidden();
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
}
