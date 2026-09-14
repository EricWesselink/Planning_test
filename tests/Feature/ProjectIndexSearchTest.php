<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectIndexSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_by_project_number_shows_only_matching_work(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077', 'Referentie: 11P251047 Griftland college');
        $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090', 'Referentie: 11P260141 Laakse Tuinen Amersfoort');

        $this->actingAs($user)
            ->get(route('projects.index', ['q' => '11P251047']))
            ->assertOk()
            ->assertSee('Griftland college')
            ->assertSee('11P251047')
            ->assertDontSee('Laakse Tuinen');
    }

    public function test_search_by_work_name_shows_only_matching_work(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090');

        $this->actingAs($user)
            ->get(route('projects.index', ['q' => 'Griftland']))
            ->assertOk()
            ->assertSee('Griftland college')
            ->assertDontSee('Laakse Tuinen');
    }

    public function test_search_by_work_number_shows_only_matching_work(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090');

        $this->actingAs($user)
            ->get(route('projects.index', ['q' => '251000077']))
            ->assertOk()
            ->assertSee('Griftland college')
            ->assertDontSee('Laakse Tuinen');
    }

    public function test_empty_search_shows_all_active_projects(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090');

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Zoek op projectnr. of werk')
            ->assertSee('Griftland college')
            ->assertSee('Laakse Tuinen');
    }

    public function test_search_does_not_reveal_inaccessible_projects(): void
    {
        $visible = $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeProject('11P251047 Extra college', '251000099');
        $user = User::factory()->limitedAccess()->create();
        $user->projects()->attach($visible);

        $this->actingAs($user)
            ->get(route('projects.index', ['q' => '11P251047']))
            ->assertOk()
            ->assertSee('Griftland college')
            ->assertDontSee('Extra college');
    }

    public function test_search_wildcard_characters_do_not_match_every_project(): void
    {
        $user = User::factory()->create();
        $this->makeProject('11P251047 Griftland college', '251000077');
        $this->makeProject('11P260141 Laakse Tuinen Amersfoort', '260200090');

        $this->actingAs($user)
            ->get(route('projects.index', ['q' => '%']))
            ->assertOk()
            ->assertSee('Geen projecten voor')
            ->assertDontSee('Griftland college')
            ->assertDontSee('Laakse Tuinen');
    }

    private function makeProject(string $name, string $number, ?string $notes = null): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create([
            'project_number' => $number,
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'gepland',
            'notes' => $notes,
        ]);
    }
}
