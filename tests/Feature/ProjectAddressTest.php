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

    public function test_planner_can_fill_in_a_project_number_later(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->patch(route('projects.update', $project), [
                'work_code' => '11p260521',
                'address' => $project->address,
                'postal_code' => $project->postal_code,
                'city' => $project->city,
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('11P260521', $project->workCode());
        $this->assertSame('Laakse Tuinen Amersfoort', $project->displayTitle());

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('11P260521');
    }

    public function test_project_list_shows_editable_work_address_fields(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $project->update([
            'address' => 'Schoolstraat 1',
            'postal_code' => '3811 AA',
            'city' => 'Amersfoort',
        ]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Werkadres')
            ->assertSee('Opdrachtgever')
            ->assertSee('name="work_address"', false)
            ->assertDontSee('name="postal_code"', false)
            ->assertDontSee('name="city"', false)
            ->assertSee('name="customer_name"', false)
            ->assertSee('value="Schoolstraat 1, 3811 AA Amersfoort"', false)
            ->assertSee('value="Hegeman"', false)
            ->assertSee('data-autosave', false)
            ->assertSee('data-autosave-status', false)
            ->assertSee('form="plan-'.$project->id.'"', false)
            ->assertSee('name="start_date"', false)
            ->assertSee('name="start_week"', false)
            ->assertSee('name="klaar_date"', false)
            ->assertSee('name="klaar_week"', false)
            ->assertDontSee('>Opslaan<', false)
            ->assertDontSee('>Straat</label>', false);
    }

    public function test_autosave_keeps_opdrachtgever_after_reload(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $project->update([
            'address' => 'Schoolstraat 1',
            'postal_code' => '3811 AA',
            'city' => 'Amersfoort',
            'planned_start_date' => '2026-09-21',
            'planned_end_date' => '2026-10-17',
        ]);

        $this->actingAs($user)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->post(route('projects.update', $project), [
                '_method' => 'PATCH',
                'customer_name' => 'Stichting Griftland',
                'address' => 'Schoolstraat 1',
                'postal_code' => '3811 AA',
                'city' => 'Amersfoort',
                'start_date' => '2026-09-21',
                'start_year' => '2026',
                'start_week' => '39',
                'klaar_date' => '2026-10-17',
                'klaar_year' => '2026',
                'klaar_week' => '42',
            ])
            ->assertOk()
            ->assertJson(['status' => 'Project opgeslagen.']);

        $this->assertSame('Stichting Griftland', $project->fresh()->customer?->name);
        $this->assertSame('2026-09-21', $project->fresh()->planned_start_date?->toDateString());
        $this->assertSame('2026-10-17', $project->fresh()->planned_end_date?->toDateString());

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('value="Stichting Griftland"', false)
            ->assertSee('value="Schoolstraat 1, 3811 AA Amersfoort"', false)
            ->assertSee('value="2026-09-21"', false)
            ->assertSee('value="2026-10-17"', false);
    }

    public function test_autosave_keeps_work_address_after_reload(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->post(route('projects.update', $project), [
                '_method' => 'PATCH',
                'customer_name' => 'Hegeman',
                'address' => 'Praamgracht 8',
                'postal_code' => '3815 AA',
                'city' => 'Amersfoort',
            ])
            ->assertOk();

        $project->refresh();
        $this->assertSame('Praamgracht 8', $project->address);
        $this->assertSame('3815 AA', $project->postal_code);
        $this->assertSame('Amersfoort', $project->city);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('value="Praamgracht 8, 3815 AA Amersfoort"', false);
    }

    public function test_project_list_saves_an_opdrachtgever(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $existing = Customer::query()->create(['name' => 'Stichting Griftland']);

        $this->actingAs($user)
            ->from(route('projects.index'))
            ->patch(route('projects.update', $project), [
                'planning_project_id' => $project->id,
                'customer_name' => 'Stichting Griftland',
                'address' => $project->address,
                'postal_code' => $project->postal_code,
                'city' => $project->city,
            ])
            ->assertRedirect(route('projects.index'));

        $project->refresh();
        $this->assertSame($existing->id, $project->customer_id);
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_project_list_creates_an_opdrachtgever_when_the_name_is_new(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->from(route('projects.index'))
            ->patch(route('projects.update', $project), [
                'planning_project_id' => $project->id,
                'customer_name' => 'Stichting Griftland',
            ])
            ->assertRedirect(route('projects.index'));

        $project->refresh();
        $this->assertSame('Stichting Griftland', $project->customer?->name);
        $this->assertTrue(Customer::query()->where('name', 'Hegeman')->exists());
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_rejects_an_empty_opdrachtgever(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->from(route('projects.index'))
            ->patch(route('projects.update', $project), [
                'customer_name' => '',
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['customer_name' => 'Vul een opdrachtgever in.']);

        $this->assertSame('Hegeman', $project->fresh()->customer?->name);
    }

    public function test_json_update_saves_an_opdrachtgever_without_a_redirect(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->patchJson(route('projects.update', $project), [
                'customer_name' => 'Stichting Griftland',
            ])
            ->assertOk()
            ->assertJson(['status' => 'Project opgeslagen.']);

        $this->assertSame('Stichting Griftland', $project->fresh()->customer?->name);
    }

    public function test_json_update_rejects_an_empty_opdrachtgever(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->patchJson(route('projects.update', $project), [
                'customer_name' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.customer_name.0', 'Vul een opdrachtgever in.');

        $this->assertSame('Hegeman', $project->fresh()->customer?->name);
    }

    public function test_project_list_saves_a_work_address(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->from(route('projects.index'))
            ->patch(route('projects.update', $project), [
                'planning_project_id' => $project->id,
                'address' => 'Schoolstraat 1',
                'postal_code' => '3811 AA',
                'city' => 'Amersfoort',
            ])
            ->assertRedirect(route('projects.index'));

        $project->refresh();
        $this->assertSame('Schoolstraat 1', $project->address);
        $this->assertSame('3811 AA', $project->postal_code);
        $this->assertSame('Amersfoort', $project->city);
    }

    public function test_uitvoerder_cannot_edit_the_work_address_on_the_project_list(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $project = $this->makeProject();
        $project->update([
            'address' => 'Schoolstraat 1',
            'city' => 'Amersfoort',
        ]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Schoolstraat 1, Amersfoort')
            ->assertSee('Hegeman')
            ->assertDontSee('name="address"', false)
            ->assertDontSee('name="customer_name"', false)
            ->assertDontSee('>Opslaan<', false);
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
