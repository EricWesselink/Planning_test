<?php

namespace Tests\Feature;

use App\Enums\AvailabilityKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerPersonnelTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_menu_places_personnel_between_workers_and_users(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Vakmensen / ZZP', 'Personeel', 'Gebruikers']);
    }

    public function test_personnel_page_lists_each_own_employee_and_excludes_zzp(): void
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
            ->get(route('personnel.index', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Personeel')
            ->assertSee('Eric Wesselink')
            ->assertSee('Harm Wesselink')
            ->assertSee('>Ma 7</th>', false)
            ->assertSee('>Za 12</th>', false)
            ->assertSee('Vaste werkdagen')
            ->assertSee('Afwezigheid')
            ->assertDontSee('Nick Seine')
            ->assertDontSee('Vrij op vrijdag');
    }

    public function test_workers_page_does_not_show_personnel_or_absence_subtabs(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Vakmensen / ZZP')
            ->assertSee('Personeel')
            ->getContent();

        $this->assertStringNotContainsString('board-tabs', $html);
        $this->assertStringNotContainsString('>Teams</a>', $html);
    }

    public function test_old_vakmensen_personnel_urls_redirect_to_personnel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/vakmensen/personeel')
            ->assertRedirect('/personeel');

        $this->actingAs($user)
            ->get('/vakmensen/afwezigheid')
            ->assertRedirect('/personeel');
    }

    public function test_week_overview_shows_hours_vrij_and_vacation(): void
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
        $eric->setRelation('worker', $team);
        $eric->setWorkDay(5, false);
        $eric->save();
        $team->availabilities()->create([
            'crew_member_id' => $eric->id,
            'start_date' => '2026-09-09',
            'end_date' => '2026-09-09',
            'kind' => AvailabilityKind::Vacation,
        ]);
        $item = $this->makeWorkItem();
        $assignment = new WorkerAssignment([
            'worker_id' => $team->id,
            'project_id' => $item->project_id,
            'work_item_id' => $item->id,
            'people_count' => 1,
        ]);
        $assignment->applySchedule(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            '08:00:00',
            '16:00:00',
        );
        $assignment->save();
        $assignment->syncPresentCrew([$eric->id]);

        $this->actingAs($user)
            ->get(route('personnel.index', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('8u')
            ->assertSee('Vakantie')
            ->assertSee('Vrij');
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
            ->from(route('personnel.index'))
            ->patch(route('personnel.work-days.update', [$team, $eric]), [
                'day' => 5,
                'works' => '0',
            ])
            ->assertRedirect(route('personnel.index'))
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
            ->patch(route('personnel.work-days.update', [$nick, $member]), [
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
            ->patch(route('personnel.work-days.update', [$peter, $member]), [
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
            ->from(route('personnel.index'))
            ->patch(route('personnel.work-days.update', [$peter, $member]), [
                'day' => 7,
                'works' => '1',
            ])
            ->assertRedirect(route('personnel.index'))
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
            ->from(route('personnel.index'))
            ->post(route('workers.availability.store', $team), [
                'crew_member_id' => $eric->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'kind' => AvailabilityKind::Vacation->value,
            ])
            ->assertRedirect(route('personnel.index'))
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
            ->patch(route('personnel.work-days.update', [$peter, $member]), [
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

        $this->get(route('personnel.index'))->assertRedirect(route('login'));
        $this->patch(route('personnel.work-days.update', [$peter, $member]), [
            'day' => 5,
            'works' => '0',
        ])->assertRedirect(route('login'));
    }

    public function test_vakman_cannot_open_personnel(): void
    {
        $peter = $this->makeWorker('Peter', 'eigen');
        $user = User::factory()->vakman($peter->id)->create();

        $this->actingAs($user)
            ->get(route('personnel.index'))
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

    private function makeWorkItem(): WorkItem
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
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
    }
}
