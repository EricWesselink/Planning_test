<?php

namespace Tests\Feature;

use App\Enums\CalculationStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Enums\WorkTicketKind;
use App\Models\Calculation;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\WorkActivityCategory;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ReadOnlyAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_user_can_open_office_overviews(): void
    {
        $user = User::factory()->alleenLezen()->create();
        $project = $this->makeProject();
        $calculation = $this->makeCalculation($user);
        $ticket = $this->makeWorkTicket($project);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Alleen lezen');
        $this->actingAs($user)->get(route('projects.index'))->assertOk();
        $this->actingAs($user)->get(route('projects.show', $project))->assertOk();
        $this->actingAs($user)->get(route('planning'))->assertOk();
        $this->actingAs($user)->get(route('calculations.index'))->assertOk();
        $this->actingAs($user)->get(route('calculations.show', $calculation))->assertOk();
        $this->actingAs($user)->get(route('workers.index'))->assertOk();
        $this->actingAs($user)->get(route('work-tickets.show', $ticket))->assertOk();
        $this->actingAs($user)->get(route('projects.pdf'))->assertOk();
    }

    public function test_read_only_user_cannot_mutate_records_via_direct_requests(): void
    {
        $user = User::factory()->alleenLezen()->create();
        $project = $this->makeProject(['name' => 'Kindcentrum']);
        $calculation = $this->makeCalculation($user);
        $ticket = $this->makeWorkTicket($project);
        $worker = Worker::query()->create(['name' => 'Albert', 'employment_type' => 'eigen', 'active' => true]);
        $target = User::factory()->create(['name' => 'Planner Piet']);

        $this->actingAs($user)
            ->patch(route('projects.update', $project), ['city' => 'Utrecht'])
            ->assertForbidden();
        $this->assertSame('Amersfoort', $project->fresh()->city);

        $this->actingAs($user)->delete(route('projects.destroy', $project))->assertForbidden();
        $this->assertModelExists($project);

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'start_date' => '2026-12-01',
                'end_date' => '2026-12-01',
                'people_count' => 1,
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('worker_assignments', [
            'project_id' => $project->id,
            'start_date' => '2026-12-01',
        ]);

        $this->actingAs($user)
            ->post(route('projects.plattegrond.store', $project), [
                'plattegrond' => UploadedFile::fake()->image('plan.jpg', 40, 30),
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('calculations.update', $calculation), ['name' => 'Gewijzigd', 'dated_on' => '2026-03-06'])
            ->assertForbidden();
        $this->assertSame('Offerte Wepro', $calculation->fresh()->name);

        $this->actingAs($user)
            ->patch(route('work-tickets.hours.update', $ticket), ['worked_hours' => 8])
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), ['name' => 'Gewijzigd'])
            ->assertForbidden();
        $this->assertSame('Albert', $worker->fresh()->name);

        $this->actingAs($user)
            ->patch(route('users.update', $target), [
                'name' => 'Hacker',
                'email' => $target->email,
                'role' => UserRole::Admin->value,
                'active' => '1',
                'project_access' => 'all',
            ])
            ->assertForbidden();
        $this->assertSame('Planner Piet', $target->fresh()->name);
        $this->assertSame(UserRole::Planner, $target->fresh()->role);
    }

    public function test_read_only_user_does_not_see_user_management(): void
    {
        $user = User::factory()->alleenLezen()->create();

        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('href="'.url('/gebruikers').'"', false);
    }

    public function test_read_only_user_cannot_create_or_change_users_or_the_catalog(): void
    {
        $user = User::factory()->alleenLezen()->create();
        $target = User::factory()->create([
            'name' => 'Planner Piet',
            'email' => 'piet@niconvloeren.nl',
            'role' => UserRole::Planner,
        ]);
        $category = WorkActivityCategory::query()->create([
            'name' => 'Vloeren',
            'slug' => 'vloeren-readonly-test',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $activity = WorkActivity::query()->create([
            'work_activity_category_id' => $category->id,
            'name' => 'Linoleum',
            'slug' => 'linoleum-readonly-test',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('users.create'))->assertForbidden();
        $this->actingAs($user)->get(route('users.show', $target))->assertForbidden();
        $this->actingAs($user)->get(route('work-activities.index'))->assertForbidden();
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('href="'.url('/beheer/werkzaamheden').'"', false);

        $this->actingAs($user)
            ->post(route('users.store'), [
                'name' => 'Nieuwe collega',
                'email' => 'collega@niconvloeren.nl',
                'password' => 'wachtwoord123',
                'password_confirmation' => 'wachtwoord123',
                'role' => UserRole::Planner->value,
                'active' => '1',
                'project_access' => 'all',
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'collega@niconvloeren.nl']);

        $this->actingAs($user)
            ->patch(route('users.update', $target), [
                'name' => 'Hacker',
                'email' => $target->email,
                'role' => UserRole::Admin->value,
                'permissions' => [Permission::UsersManage->value],
                'active' => '1',
                'project_access' => 'all',
            ])
            ->assertForbidden();
        $target->refresh();
        $this->assertSame('Planner Piet', $target->name);
        $this->assertSame(UserRole::Planner, $target->role);

        $this->actingAs($user)->delete(route('users.destroy', $target))->assertForbidden();
        $this->assertModelExists($target);

        $this->actingAs($user)
            ->post(route('work-activities.store'), [
                'work_activity_category_id' => $category->id,
                'name' => 'Horren',
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('work_activities', ['name' => 'Horren']);

        $this->actingAs($user)
            ->patch(route('work-activities.update', $activity), [
                'work_activity_category_id' => $category->id,
                'name' => 'Gewijzigd',
                'sort_order' => 1,
                'is_active' => '0',
            ])
            ->assertForbidden();
        $activity->refresh();
        $this->assertSame('Linoleum', $activity->name);
        $this->assertTrue($activity->is_active);
    }

    public function test_admin_can_manage_users_and_the_catalog(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create([
            'name' => 'Gerrit',
            'role' => UserRole::Planner,
        ]);
        $category = WorkActivityCategory::query()->create([
            'name' => 'Overig',
            'slug' => 'overig-admin-test',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->actingAs($admin)->get(route('work-activities.index'))->assertOk();

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'name' => 'Uitvoerder Jan',
                'email' => 'jan@niconvloeren.nl',
                'password' => 'wachtwoord123',
                'password_confirmation' => 'wachtwoord123',
                'role' => UserRole::Uitvoerder->value,
                'active' => '1',
                'project_access' => 'all',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => 'jan@niconvloeren.nl',
            'role' => UserRole::Uitvoerder->value,
        ]);

        $this->actingAs($admin)
            ->patch(route('users.update', $target), [
                'name' => 'Gerrit Bakker',
                'email' => $target->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => UserRole::Projectleider->value,
                'active' => '1',
                'project_access' => 'all',
            ])
            ->assertRedirect();
        $this->assertSame(UserRole::Projectleider, $target->fresh()->role);

        $this->actingAs($admin)
            ->post(route('work-activities.store'), [
                'work_activity_category_id' => $category->id,
                'name' => 'Nicon catalogusrecht',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('work_activities', ['name' => 'Nicon catalogusrecht']);
    }

    private function makeProject(array $overrides = []): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create(array_merge([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Kindcentrum Veldhoeve',
            'city' => 'Amersfoort',
            'status' => 'gepland',
        ], $overrides));
    }

    private function makeCalculation(User $user): Calculation
    {
        return Calculation::query()->create([
            'name' => 'Offerte Wepro',
            'dated_on' => '2026-03-06',
            'status' => CalculationStatus::Concept,
            'created_by' => $user->id,
        ]);
    }

    private function makeWorkTicket(Project $project): WorkTicket
    {
        $worker = Worker::query()->create([
            'name' => 'Nick',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);

        return WorkTicket::query()->create([
            'number' => 'WB-2026-0001',
            'kind' => WorkTicketKind::Werkbon,
            'worker_assignment_id' => $assignment->id,
            'project_id' => $project->id,
            'worker_id' => $worker->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
        ]);
    }
}
