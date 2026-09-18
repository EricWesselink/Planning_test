<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VakmanAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_vakman_sees_only_projects_where_he_is_scheduled(): void
    {
        [$nick, $own, $other] = $this->seedScheduledAndOtherProject();
        $user = User::factory()->vakman($nick->id)->create([
            'name' => 'Nick Seine',
            'can_access_all_projects' => true,
        ]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Laakse Tuinen')
            ->assertDontSee('Kindcentrum Veldhoeve')
            ->assertDontSee('Nieuw project')
            ->assertDontSee('>Archief</a>', false);

        $this->actingAs($user)->get(route('projects.show', $own))->assertOk()->assertSee('Laakse Tuinen');
        $this->actingAs($user)->get(route('projects.show', $other))->assertForbidden();
    }

    public function test_vakman_planning_shows_only_his_own_assignments(): void
    {
        [$nick, $own, $other] = $this->seedScheduledAndOtherProject();
        $kees = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $kees->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-10',
            'hours_per_day' => 8,
        ]);
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Laakse Tuinen')
            ->assertDontSee('Kindcentrum Veldhoeve')
            ->assertSee('Nick Seine')
            ->assertDontSee('Kees Jansen')
            ->assertDontSee('data-worker-id="'.$kees->id.'"', false)
            ->assertSee('data-readonly="1"', false)
            ->assertDontSee('Iedereen')
            ->assertDontSee('Nog niet ingepland');

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'worker_id' => $kees->id,
                'project_id' => $other->id,
            ]))
            ->assertForbidden();
    }

    public function test_vakman_cannot_change_planning(): void
    {
        [$nick, $own] = $this->seedScheduledAndOtherProject();
        $assignment = WorkerAssignment::query()
            ->where('worker_id', $nick->id)
            ->where('project_id', $own->id)
            ->first();
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $nick->id,
                'project_id' => $own->id,
                'start_date' => '2026-09-11',
                'end_date' => '2026-09-11',
                'people_count' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $nick->id,
                'start_date' => '2026-09-11',
                'end_date' => '2026-09-11',
            ])
            ->assertForbidden();
    }

    public function test_vakman_does_not_see_office_menus_and_cannot_open_vakmensen(): void
    {
        $nick = $this->makeWorker('Nick Seine');
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('href="'.route('vakman.planning').'"', false)
            ->assertDontSee('href="'.url('/planning').'"', false)
            ->assertDontSee('href="'.url('/projecten').'"', false)
            ->assertDontSee('href="'.url('/vakmensen').'"', false)
            ->assertDontSee('href="'.url('/gebruikers').'"', false)
            ->assertDontSee('href="'.url('/projecten/archief').'"', false)
            ->assertDontSee('https://app.decoloop.com/dossier/floor_browse', false)
            ->assertDontSee('images/decoloop.png', false);

        $this->actingAs($user)->get(route('workers.index'))->assertForbidden();
        $this->actingAs($user)->get(route('workers.absence'))->assertForbidden();
        $this->actingAs($user)->get(route('workers.personnel'))->assertForbidden();
    }

    public function test_vakman_dashboard_hides_projects_where_he_is_not_scheduled(): void
    {
        [$nick, $own, $other] = $this->seedScheduledAndOtherProject();
        $user = User::factory()->vakman($nick->id)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Laakse Tuinen')
            ->assertDontSee('Kindcentrum Veldhoeve');

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertDontSee('Kindcentrum Veldhoeve');

        $this->actingAs($user)
            ->get(route('production.index', ['project_id' => $other->id]))
            ->assertForbidden();
    }

    public function test_admin_can_create_a_vakman_team_with_member_logins(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'name' => 'Team Wespro',
                'email' => 'wespro@niconvloeren.nl',
                'password' => 'wachtwoord123',
                'password_confirmation' => 'wachtwoord123',
                'role' => UserRole::Vakman->value,
                'active' => '1',
                'employment_type' => 'zzp',
                'people_count' => '2',
                'crew_members' => [
                    ['name' => 'Peter', 'email' => '', 'password' => ''],
                    ['name' => 'Kees Jansen', 'email' => 'kees@niconvloeren.nl', 'password' => 'lidwacht1'],
                ],
            ])
            ->assertRedirect();

        $team = User::query()->where('email', 'wespro@niconvloeren.nl')->first();
        $this->assertNotNull($team);
        $this->assertSame(UserRole::Vakman, $team->role);
        $this->assertSame(false, $team->can_access_all_projects);
        $this->assertNotNull($team->worker_id);
        $this->assertSame('Team Wespro', $team->worker->name);
        $this->assertSame('zzp', $team->worker->employment_type->value);
        $this->assertSame(2, $team->worker->peopleCount());
        $this->assertSame(['Peter', 'Kees Jansen'], $team->worker->crewPeople->pluck('name')->all());

        $member = User::query()->where('email', 'kees@niconvloeren.nl')->first();
        $this->assertNotNull($member);
        $this->assertSame(UserRole::Vakman, $member->role);
        $this->assertSame($team->worker_id, $member->worker_id);
        $this->assertNotNull($member->crew_member_id);
        $this->assertTrue(Hash::check('lidwacht1', $member->password));
    }

    public function test_vakman_team_requires_type_and_size(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('users.create'))
            ->post(route('users.store'), [
                'name' => 'Nick Seine',
                'email' => 'nick@niconvloeren.nl',
                'password' => 'wachtwoord123',
                'password_confirmation' => 'wachtwoord123',
                'role' => UserRole::Vakman->value,
                'active' => '1',
            ])
            ->assertRedirect(route('users.create'))
            ->assertSessionHasErrors(['employment_type', 'people_count']);
    }

    public function test_team_member_login_sees_the_same_scheduled_projects(): void
    {
        [$nick, $own, $other] = $this->seedScheduledAndOtherProject();
        $lead = User::factory()->vakman($nick->id)->create(['email' => 'nick@niconvloeren.nl']);
        $lid = User::factory()->vakman($nick->id)->create([
            'name' => 'Kees',
            'email' => 'kees@niconvloeren.nl',
        ]);

        $this->actingAs($lid)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Laakse Tuinen')
            ->assertDontSee('Kindcentrum Veldhoeve');

        $this->actingAs($lid)->get(route('projects.show', $own))->assertOk();
        $this->actingAs($lid)->get(route('projects.show', $other))->assertForbidden();
        $this->assertSame($lead->worker_id, $lid->worker_id);
    }

    public function test_create_form_shows_team_fields_for_a_vakman(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('users.create'))
            ->assertOk()
            ->assertSee('Vakman')
            ->assertSee('Eigen personeel of ZZP')
            ->assertSee('Aantal personen')
            ->assertSee('Vakkennis')
            ->assertSee('name="specialties[]"', false)
            ->assertSee('Bijzonderheden')
            ->assertSee('name="phone"', false)
            ->assertSee('name="address"', false);
    }

    public function test_admin_saves_vakkennis_and_bijzonderheden_when_creating_a_team(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'name' => 'Team Wespro',
                'email' => 'wespro@niconvloeren.nl',
                'password' => 'wachtwoord123',
                'password_confirmation' => 'wachtwoord123',
                'role' => UserRole::Vakman->value,
                'active' => '1',
                'employment_type' => 'zzp',
                'people_count' => '2',
                'specialties' => ['pvc', 'plinten'],
                'phone' => '06 12345678',
                'contact_name' => 'Piet',
                'address' => 'Industrieweg 8',
                'postal_code' => '8013 PM',
                'city' => 'Zwolle',
                'crew_members' => [
                    ['name' => 'Peter', 'email' => '', 'password' => ''],
                    ['name' => 'Kees Jansen', 'email' => 'kees@niconvloeren.nl', 'password' => 'lidwacht1'],
                ],
            ])
            ->assertRedirect();

        $team = User::query()->where('email', 'wespro@niconvloeren.nl')->first();
        $this->assertNotNull($team);
        $this->assertSame('PVC, Plinten', $team->worker->specialty);
        $this->assertSame('06 12345678', $team->worker->phone);
        $this->assertSame('Piet', $team->worker->contact_name);
        $this->assertSame('Industrieweg 8', $team->worker->address);
        $this->assertSame('8013 PM', $team->worker->postal_code);
        $this->assertSame('Zwolle', $team->worker->city);

        $this->actingAs($admin)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Team Wespro')
            ->assertSee('PVC, Plinten');
    }

    public function test_admin_can_delete_a_vakman_user_without_removing_the_team(): void
    {
        $admin = User::factory()->admin()->create();
        $nick = $this->makeWorker('Nick Seine');
        $user = User::factory()->vakman($nick->id)->create(['name' => 'Nick Seine']);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Verwijderen');

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->delete(route('users.destroy', $user))
            ->assertRedirect(route('users.index'));

        $this->assertModelMissing($user);
        $this->assertModelExists($nick);
    }

    /**
     * @return array{0: Worker, 1: Project, 2: Project}
     */
    private function seedScheduledAndOtherProject(): array
    {
        $nick = $this->makeWorker('Nick Seine');
        $own = $this->makeProject('Laakse Tuinen');
        $other = $this->makeProject('Kindcentrum Veldhoeve');

        WorkerAssignment::query()->create([
            'worker_id' => $nick->id,
            'project_id' => $own->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);

        return [$nick, $own, $other];
    }

    private function makeWorker(string $name): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'active' => true,
        ]);
    }

    private function makeProject(string $name): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'gepland',
        ]);
    }
}
