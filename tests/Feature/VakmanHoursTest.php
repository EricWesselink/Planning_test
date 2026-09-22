<?php

namespace Tests\Feature;

use App\Enums\TimeEntryStatus;
use App\Models\CrewMember;
use App\Models\Customer;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VakmanHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_the_vakman_login(): void
    {
        $this->get(route('vakman.hours.index'))
            ->assertRedirect(route('vakman.login'));
    }

    public function test_planner_cannot_open_mijn_uren(): void
    {
        $planner = User::factory()->create();

        $this->actingAs($planner)
            ->get(route('vakman.hours.index'))
            ->assertForbidden();
    }

    public function test_vakman_nav_lists_mijn_uren_beside_planning(): void
    {
        $vakman = $this->makeVakman('Peter');

        $html = $this->actingAs($vakman)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Mijn uren')
            ->assertSee(route('vakman.hours.index'), false)
            ->getContent();

        $planning = strpos($html, 'Mijn planning');
        $hours = strpos($html, 'Mijn uren');
        $password = strpos($html, 'Wachtwoord');
        $leave = strpos($html, 'Vrij aanvragen');
        $logout = strpos($html, 'Uitloggen');

        $this->assertNotFalse($planning);
        $this->assertNotFalse($hours);
        $this->assertNotFalse($password);
        $this->assertNotFalse($leave);
        $this->assertNotFalse($logout);
        $this->assertLessThan($hours, $planning);
        $this->assertLessThan($password, $hours);
        $this->assertLessThan($leave, $password);
        $this->assertLessThan($logout, $leave);
    }

    public function test_vakman_sees_own_hours_and_week_totals_for_the_current_week(): void
    {
        $this->travelTo('2026-09-22 08:00:00');
        $vakman = $this->makeVakman('Peter');
        $project = $this->makeProject('Griftland College');
        foreach (['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'] as $date) {
            $this->makeEntry($vakman, $project, [
                'date' => $date,
                'hours' => 8,
                'start_time' => '07:30:00',
                'end_time' => '16:30:00',
                'break_minutes' => 60,
                'status' => TimeEntryStatus::Approved,
                'approved_hours' => 8,
                'approved_start_time' => '07:30:00',
                'approved_end_time' => '16:30:00',
                'approved_break_minutes' => 60,
            ]);
        }
        $this->makeEntry($vakman, $project, [
            'date' => '2026-09-14',
            'hours' => 4,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'break_minutes' => 0,
        ]);

        $this->actingAs($vakman)
            ->get(route('vakman.hours.index'))
            ->assertOk()
            ->assertSee('Maandag 21 september — totaal 8u')
            ->assertSee('Griftland College')
            ->assertSee('07:30–16:30 · Pauze 60 min · 8u')
            ->assertSee('Status: Goedgekeurd')
            ->assertSee('Totaal ingediend deze week: 40u')
            ->assertSee('Totaal goedgekeurd deze week: 40u')
            ->assertSee('Ingediend: 40u')
            ->assertSee('Goedgekeurd: 40u')
            ->assertSee('Verschil: 0u')
            ->assertDontSee('08:00–12:00');

        $this->actingAs($vakman)
            ->get(route('vakman.hours.index', ['week' => '2026-09-14']))
            ->assertOk()
            ->assertSee('08:00–12:00')
            ->assertDontSee('Totaal ingediend deze week: 40u');
    }

    public function test_adjusted_hours_show_the_submitted_and_approved_clocks(): void
    {
        $this->travelTo('2026-09-21 08:00:00');
        $vakman = $this->makeVakman('Peter');
        $project = $this->makeProject('Griftland College');
        $this->makeEntry($vakman, $project, [
            'date' => '2026-09-21',
            'hours' => 8.5,
            'start_time' => '07:00:00',
            'end_time' => '16:30:00',
            'break_minutes' => 60,
            'status' => TimeEntryStatus::Approved,
            'approved_hours' => 8,
            'approved_start_time' => '07:30:00',
            'approved_end_time' => '16:30:00',
            'approved_break_minutes' => 60,
            'review_note' => 'uren starten vanaf 07:30',
        ]);

        $this->actingAs($vakman)
            ->get(route('vakman.hours.index'))
            ->assertOk()
            ->assertSee('Ingediend: 07:00–16:30 · 8,5u')
            ->assertSee('Goedgekeurd: 07:30–16:30 · 8u')
            ->assertSee('Reden: uren starten vanaf 07:30')
            ->assertSee('Status: Aangepast & goedgekeurd');
    }

    public function test_zero_approved_hours_do_not_fall_back_to_submitted_hours(): void
    {
        $this->travelTo('2026-09-21 08:00:00');
        $vakman = $this->makeVakman('Peter');
        $project = $this->makeProject('Griftland College');
        $this->makeEntry($vakman, $project, [
            'date' => '2026-09-21',
            'hours' => 8,
            'start_time' => '07:30:00',
            'end_time' => '16:30:00',
            'break_minutes' => 60,
            'status' => TimeEntryStatus::Approved,
            'approved_hours' => 0,
            'approved_start_time' => '07:30:00',
            'approved_end_time' => '07:30:00',
            'approved_break_minutes' => 0,
        ]);

        $this->actingAs($vakman)
            ->get(route('vakman.hours.index'))
            ->assertOk()
            ->assertSee('Totaal ingediend deze week: 8u')
            ->assertSee('Totaal goedgekeurd deze week: 0u')
            ->assertSee('Goedgekeurd: 0u')
            ->assertDontSee('Totaal goedgekeurd deze week: 8u')
            ->assertDontSee('Goedgekeurd: 8u');
    }

    public function test_entries_without_clock_times_stay_visible(): void
    {
        $this->travelTo('2026-09-21 08:00:00');
        $vakman = $this->makeVakman('Peter');
        $project = $this->makeProject('Griftland College');
        $this->makeEntry($vakman, $project, [
            'date' => '2026-09-21',
            'hours' => 6.75,
            'start_time' => null,
            'end_time' => null,
            'break_minutes' => null,
            'status' => TimeEntryStatus::Submitted,
        ]);

        $this->actingAs($vakman)
            ->get(route('vakman.hours.index'))
            ->assertOk()
            ->assertSee('6,75u · oude urenregistratie')
            ->assertSee('Status: Te beoordelen')
            ->assertDontSee('07:30');
    }

    public function test_vakman_cannot_open_another_workers_hours(): void
    {
        $this->travelTo('2026-09-21 08:00:00');
        $peter = $this->makeVakman('Peter');
        $kees = $this->makeVakman('Kees');
        $own = $this->makeProject('Griftland College');
        $other = $this->makeProject('Geheim project');
        $this->makeEntry($peter, $own, [
            'date' => '2026-09-21',
            'hours' => 8,
            'start_time' => '07:30:00',
            'end_time' => '16:30:00',
            'break_minutes' => 60,
        ]);
        $this->makeEntry($kees, $other, [
            'date' => '2026-09-21',
            'hours' => 3,
            'start_time' => '12:00:00',
            'end_time' => '15:00:00',
            'break_minutes' => 0,
        ]);
        $colleague = CrewMember::query()->create([
            'worker_id' => $peter->worker_id,
            'name' => 'Collega',
        ]);
        $colleagueUser = User::factory()->vakman((int) $peter->worker_id, (int) $colleague->id)->create(['name' => 'Collega']);
        $colleagueProject = $this->makeProject('Uren van collega');
        $this->makeEntry($colleagueUser, $colleagueProject, [
            'date' => '2026-09-21',
            'hours' => 2,
            'start_time' => '15:00:00',
            'end_time' => '17:00:00',
            'break_minutes' => 0,
        ]);

        $this->actingAs($peter)
            ->get(route('vakman.hours.index', [
                'worker_id' => $kees->worker_id,
                'person' => 'worker-'.$kees->worker_id,
                'crew_member_id' => $colleague->id,
            ]))
            ->assertOk()
            ->assertSee('Griftland College')
            ->assertDontSee('Geheim project')
            ->assertDontSee('Uren van collega')
            ->assertDontSee('3u');

        $this->actingAs($kees)
            ->get(route('vakman.hours.index'))
            ->assertOk()
            ->assertSee('Geheim project')
            ->assertDontSee('Griftland College');

        $this->actingAs($colleagueUser)
            ->get(route('vakman.hours.index', ['worker_id' => $peter->worker_id]))
            ->assertOk()
            ->assertSee('Uren van collega')
            ->assertDontSee('Griftland College');
    }

    private function makeVakman(string $name): User
    {
        $worker = Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        return User::factory()->vakman($worker->id)->create(['name' => $name]);
    }

    private function makeProject(string $name): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Gemeente']);

        return Project::query()->create([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeEntry(User $vakman, Project $project, array $attributes): TimeEntry
    {
        return TimeEntry::factory()->create(array_merge([
            'worker_id' => $vakman->worker_id,
            'crew_member_id' => $vakman->crew_member_id,
            'user_id' => $vakman->id,
            'project_id' => $project->id,
            'submitted_by' => $vakman->id,
        ], $attributes));
    }
}
