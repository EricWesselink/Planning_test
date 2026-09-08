<?php

namespace Tests\Feature;

use App\Enums\AvailabilityKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkerAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_lets_a_planner_mark_friday_off_for_own_staff(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter', 'eigen');

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->patch(route('workers.friday.update', $peter), [
                'friday_off' => '1',
            ])
            ->assertRedirect(route('workers.index'));

        $this->assertTrue($peter->fresh()->friday_off);

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Vrij op vrijdag')
            ->assertSee('checked', false);
    }

    public function test_overview_lets_a_planner_set_zzp_unavailable_and_available_periods(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.availability.store', $nick), [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-12',
                'kind' => AvailabilityKind::Unavailable->value,
            ])
            ->assertRedirect(route('workers.index'));

        $window = WorkerAvailability::query()->where('worker_id', $nick->id)->first();
        $this->assertNotNull($window);
        $this->assertSame('2026-09-07', $window->start_date->toDateString());
        $this->assertSame('2026-09-12', $window->end_date->toDateString());
        $this->assertSame(AvailabilityKind::Unavailable, $window->kind);

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.availability.store', $nick), [
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-18',
                'kind' => AvailabilityKind::Available->value,
            ])
            ->assertRedirect(route('workers.index'));

        $html = $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Niet 7 sep')
            ->assertSee('Wel 14 sep')
            ->getContent();

        $this->assertStringNotContainsString('Vrij op vrijdag', $html);
    }

    #[DataProvider('employmentTypes')]
    public function test_overview_lets_a_planner_switch_a_team_fully_unavailable(string $type, string $name): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker($name, $type);

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->patch(route('workers.availability.update', $worker), [
                'unavailable' => '1',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('status', 'Staat nu helemaal niet beschikbaar.');

        $this->assertTrue($worker->fresh()->unavailable);

        $html = $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Helemaal niet beschikbaar')
            ->assertSee('text-nicon-danger">Niet beschikbaar</span>', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/name="unavailable"[^>]*checked/',
            $html,
        );

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->patch(route('workers.availability.update', $worker), [
                'unavailable' => '0',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('status', 'Staat weer beschikbaar.');

        $this->assertFalse($worker->fresh()->unavailable);
    }

    public function test_editing_the_team_does_not_clear_the_unavailable_switch(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker('Peter', 'eigen');
        $worker->update(['unavailable' => true]);

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), [
                'name' => 'Peter',
                'employment_type' => 'eigen',
                'active' => '1',
            ])
            ->assertRedirect(route('workers.show', $worker));

        $this->assertTrue($worker->fresh()->unavailable);
    }

    public function test_planner_can_remove_an_availability_period(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $window = $nick->availabilities()->create([
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-12',
            'kind' => AvailabilityKind::Unavailable,
        ]);

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->delete(route('workers.availability.destroy', [$nick, $window]))
            ->assertRedirect(route('workers.index'));

        $this->assertDatabaseMissing('worker_availabilities', [
            'id' => $window->id,
        ]);
    }

    public function test_rejects_an_end_date_before_the_start_date(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.availability.store', $nick), [
                'start_date' => '2026-09-12',
                'end_date' => '2026-09-07',
                'kind' => AvailabilityKind::Unavailable->value,
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasErrors('end_date');

        $this->assertSame(0, WorkerAvailability::query()->count());
    }

    public function test_uitvoerder_cannot_change_availability(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $peter = $this->makeWorker('Peter', 'eigen');

        $this->actingAs($user)
            ->patch(route('workers.friday.update', $peter), [
                'friday_off' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('workers.availability.update', $peter), [
                'unavailable' => '1',
            ])
            ->assertForbidden();

        $fresh = $peter->fresh();
        $this->assertFalse($fresh->friday_off);
        $this->assertFalse($fresh->unavailable);
    }

    public function test_unauthenticated_availability_update_redirects_to_login(): void
    {
        $peter = $this->makeWorker('Peter', 'eigen');

        $this->patch(route('workers.friday.update', $peter), [
            'friday_off' => '1',
        ])->assertRedirect(route('login'));

        $this->patch(route('workers.availability.update', $peter), [
            'unavailable' => '1',
        ])->assertRedirect(route('login'));
    }

    public function test_planning_candidates_mark_friday_off_as_unavailable(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter', 'eigen');
        $peter->update(['friday_off' => true, 'specialty' => 'Linoleum']);
        $item = $this->makeWorkItem('Linoleum');

        $this->actingAs($user)
            ->getJson(route('planning.candidates', [
                'work_item_id' => $item->id,
                'start_date' => '2026-09-11',
                'end_date' => '2026-09-11',
                'start_time' => '08:00',
                'end_time' => '16:00',
            ]))
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Peter',
                'selectable' => false,
                'status_label' => 'Vrij op vrijdag',
            ]);
    }

    public function test_rejects_scheduling_when_the_zzp_is_unavailable(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $nick->update(['specialty' => 'Linoleum']);
        $nick->availabilities()->create([
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-12',
            'kind' => AvailabilityKind::Unavailable,
        ]);
        $item = $this->makeWorkItem('Linoleum');

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $nick->id,
                'project_id' => $item->project_id,
                'work_item_id' => $item->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nick Seine is niet beschikbaar.');

        $this->assertDatabaseCount('worker_assignments', 0);
    }

    public function test_rejects_scheduling_when_the_team_is_switched_fully_unavailable(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'zzp');
        $nick->update(['specialty' => 'Linoleum', 'unavailable' => true]);
        $item = $this->makeWorkItem('Linoleum');

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $nick->id,
                'project_id' => $item->project_id,
                'work_item_id' => $item->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nick Seine is niet beschikbaar.');

        $this->assertDatabaseCount('worker_assignments', 0);
    }

    public function test_planning_candidates_mark_a_fully_unavailable_team(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter', 'eigen');
        $peter->update(['unavailable' => true, 'specialty' => 'Linoleum']);
        $item = $this->makeWorkItem('Linoleum');

        $this->actingAs($user)
            ->getJson(route('planning.candidates', [
                'work_item_id' => $item->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '16:00',
            ]))
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Peter',
                'selectable' => false,
                'status_label' => 'Niet beschikbaar',
            ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function employmentTypes(): array
    {
        return [
            'eigen' => ['eigen', 'Peter'],
            'zzp' => ['zzp', 'Nick Seine'],
        ];
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

    private function makeWorkItem(string $name): WorkItem
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
            'name' => $name,
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
    }
}
