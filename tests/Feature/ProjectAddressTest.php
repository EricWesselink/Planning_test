<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_work_address_update(): void
    {
        $project = $this->makeProject();

        $this->patch(route('projects.update', $project), [
            'address' => 'Schoolstraat 1',
            'city' => 'Amersfoort',
        ])->assertRedirect(route('login'));
    }

    public function test_planner_can_add_a_work_address_later_and_open_google_maps(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Project bewerken')
            ->assertDontSee('Navigeren');

        $this->actingAs($user)
            ->patch(route('projects.update', $project), [
                'address' => 'Schoolstraat 1',
                'postal_code' => '3811 AA',
                'city' => 'Amersfoort',
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('Schoolstraat 1', $project->address);
        $this->assertSame('3811 AA', $project->postal_code);
        $this->assertSame('Amersfoort', $project->city);
        $this->assertSame(
            'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode('Schoolstraat 1, 3811 AA Amersfoort').'&travelmode=driving',
            $project->googleMapsUrl()
        );

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Schoolstraat 1, 3811 AA Amersfoort')
            ->assertSee('Navigeren')
            ->assertSee($project->googleMapsUrl())
            ->assertSee('Project bewerken');
    }

    public function test_rejects_a_postal_code_longer_than_16_characters(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->patch(route('projects.update', $project), [
                'postal_code' => str_repeat('A', 17),
            ])
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHasErrors('postal_code');

        $this->assertNull($project->fresh()->postal_code);
    }

    public function test_planning_links_to_google_maps_when_the_work_has_an_address(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $project->update([
            'address' => 'Schoolstraat 1',
            'postal_code' => '3811 AA',
            'city' => 'Amersfoort',
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-10',
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Schoolstraat 1, 3811 AA Amersfoort')
            ->assertSee($project->googleMapsUrl());
    }

    private function makeProject(): Project
    {
        $customer = Customer::query()->create(['name' => 'Hegeman']);

        return Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => ProjectStatus::Gepland,
        ]);
    }
}
