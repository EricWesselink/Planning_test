<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_dashboard_redirects_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_new_work_opens_planning_with_klaar_date_and_days_left(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Gemeente Laren']);
        $project = Project::query()->create([
            'project_number' => '11P241267',
            'customer_id' => $customer->id,
            'name' => 'Gezondheidscentrum Laren',
            'city' => 'Laren',
            'status' => ProjectStatus::Gepland,
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-17',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Gezondheidscentrum Laren')
            ->assertSee('Klaar 17-09')
            ->assertSee('Nog 7 dagen')
            ->assertSee('Nog niet bemand')
            ->assertSee('/planning?project_id='.$project->id, false)
            ->assertSee('period=work', false)
            ->assertDontSee(route('projects.show', $project), false);
    }
}
