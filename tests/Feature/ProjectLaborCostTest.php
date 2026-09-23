<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectLaborCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_hourly_rate_update(): void
    {
        $project = $this->makeProject();

        $this->patch(route('projects.update', $project), [
            'basis_uurtarief' => '45,00',
        ])->assertRedirect(route('login'));

        $this->assertNull($project->fresh()->basis_uurtarief);
    }

    public function test_uitvoerder_cannot_change_the_hourly_rate(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->patch(route('projects.update', $project), [
                'basis_uurtarief' => '45,00',
            ])
            ->assertForbidden();

        $this->assertNull($project->fresh()->basis_uurtarief);
    }

    public function test_planner_saves_the_hourly_rate_and_sees_labor_on_the_planning_board(): void
    {
        $user = User::factory()->create();
        [$project] = $this->makeScheduledProject();

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->patch(route('projects.update', $project), [
                'basis_uurtarief' => '45,00',
            ])
            ->assertRedirect(route('projects.show', $project));

        $this->assertSame('45.00', $project->fresh()->basis_uurtarief);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Basis uurtarief')
            ->assertSee('Project bewerken')
            ->assertDontSee('Ingepland: 48u')
            ->assertDontSee('Tarief: €45/u');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Ingepland')
            ->assertSee('Gemaakt')
            ->assertSee('48u')
            ->assertSee('40u')
            ->assertSee('€7,50');
    }

    public function test_rejects_a_negative_hourly_rate(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->patch(route('projects.update', $project), [
                'basis_uurtarief' => '-1',
            ])
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHasErrors('basis_uurtarief');

        $this->assertNull($project->fresh()->basis_uurtarief);
    }

    public function test_planning_week_update_does_not_clear_the_hourly_rate(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $project->forceFill(['basis_uurtarief' => 45])->save();

        $this->actingAs($user)
            ->from(route('projects.index'))
            ->patch(route('projects.update', $project), [
                'planning_project_id' => $project->id,
                'start_year' => 2026,
                'start_week' => 40,
                'klaar_year' => 2026,
                'klaar_week' => 44,
            ])
            ->assertRedirect(route('projects.index'));

        $this->assertSame('45.00', $project->fresh()->basis_uurtarief);
    }

    public function test_planning_board_shows_compact_labor_from_the_existing_schedule(): void
    {
        $user = User::factory()->create();
        [$project] = $this->makeScheduledProject();
        $project->forceFill(['basis_uurtarief' => 45])->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Begroot')
            ->assertSee('Gepland')
            ->assertSee('Gemaakt')
            ->assertSee('plan-labor-block', false)
            ->assertSee('€/m²')
            ->assertSee('plan-board--labor', false)
            ->assertSee('48u')
            ->assertSee('40u')
            ->assertSee('—')
            ->assertSee('€7,50')
            ->assertSee('Werkelijk €/m²')
            ->assertDontSee('+8u')
            ->assertDontSee('48u · €45/u · €1.800 arbeid · €7,50/m²', false);
    }

    public function test_planning_board_renders_a_control_to_fold_labor_columns(): void
    {
        $user = User::factory()->create();
        [$project] = $this->makeScheduledProject();
        $project->forceFill(['basis_uurtarief' => 45])->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('data-labor-fold', false)
            ->assertSee('›')
            ->assertSee('‹')
            ->assertSee('Urenkolommen invouwen')
            ->assertSee('plan-labor-block', false)
            ->assertSee('data-labor-fold-key="nicon.planning.laborFolded"', false)
            ->assertSee('plan-board--labor-collapsed', false);
    }

    public function test_planning_board_shows_budget_and_actual_price_per_square_meter(): void
    {
        $user = User::factory()->create();
        [$project, $assignment, $item] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 702,
            actualHours: 38,
        );
        $project->forceFill(['basis_uurtarief' => 45])->save();
        $item->forceFill([
            'ordered_quantity' => 702,
            'begrote_uren' => 30.1,
            'begrote_hoeveelheid' => 702,
        ])->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Begroot €/m²')
            ->assertSee('Werkelijk €/m²')
            ->assertSee('Prognose €/m²')
            ->assertSee('€1,93')
            ->assertSee('€2,44')
            ->assertSee('+€0,51/m²')
            ->assertSee('plan-cell--labor-over', false)
            ->assertSee('Begroot: 30,1u × €45 / 702m² = €1,93/m²', false)
            ->assertSee('Prognose: 38u × €45 / 702m² = €2,44/m²', false)
            ->assertSee('Werkelijk: 38u × €45 / 702m² = €2,44/m²', false)
            ->assertSee('Verschil: +€0,51/m²', false);

        $this->assertSame($assignment->work_item_id, $item->id);
    }

    public function test_planning_board_shows_forecast_price_when_planned_hours_exceed_budget(): void
    {
        $user = User::factory()->create();
        [$project, $assignment, $item] = $this->makeScheduledProject(
            people: 2,
            start: '2026-09-07',
            end: '2026-09-09',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 0,
            actualHours: 0,
        );
        $project->forceFill(['basis_uurtarief' => 48])->save();
        $item->forceFill([
            'name' => 'Primen & Egaliseren',
            'ordered_quantity' => 702,
            'begrote_uren' => 30.1,
            'begrote_hoeveelheid' => 702,
            'uurtarief' => 48,
        ])->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Prognose €/m²')
            ->assertSee('€2,06')
            ->assertSee('€3,28')
            ->assertSee('+€1,22/m²')
            ->assertSee('+59%')
            ->assertSee('plan-cell--labor-over', false)
            ->assertSee('Prognose: 48u × €48 / 702m² = €3,28/m²', false)
            ->assertSee('Verschil: +€1,22/m²', false);

        $this->assertSame($assignment->work_item_id, $item->id);
    }

    public function test_resizing_an_assignment_recalculates_the_forecast_price_on_the_next_board_load(): void
    {
        $user = User::factory()->create();
        [$project, $assignment, $item] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 0,
            actualHours: 0,
        );
        $project->forceFill(['basis_uurtarief' => 48])->save();
        $item->forceFill([
            'ordered_quantity' => 702,
            'begrote_uren' => 30.1,
            'begrote_hoeveelheid' => 702,
            'uurtarief' => 48,
        ])->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('€0,55');

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '12:00',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('€0,27')
            ->assertDontSee('€0,55');
    }

    public function test_labor_column_headers_stay_sentence_case_so_they_do_not_overlap(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('.plan-board--labor', $css);
        $this->assertStringContainsString('--planning-sidebar-width: 868px', $css);
        $this->assertStringContainsString('plan-board--labor-collapsed', $css);
        $this->assertStringContainsString('--plan-labor-width: 486px', $css);
        $this->assertStringContainsString('--plan-col-begroot: 60px', $css);
        $this->assertStringContainsString('--plan-col-m2-budget: 58px', $css);
        $this->assertStringContainsString('--plan-col-m2-forecast: 62px', $css);
        $this->assertStringContainsString('--plan-col-m2-delta: 68px', $css);
        $this->assertStringContainsString('transition: grid-template-columns 0.28s ease', $css);
        $this->assertStringContainsString('.plan-labor-block', $css);
        $this->assertStringContainsString('.plan-cell--labor-head', $css);
        $this->assertStringContainsString('.sticky-head .plan-cell--labor-head', $css);
        $this->assertStringContainsString('text-transform: none', $css);
        $this->assertStringContainsString('.plan-cell--labor', $css);
    }

    public function test_resizing_an_assignment_recalculates_labor_on_the_next_board_load(): void
    {
        $user = User::factory()->create();
        [$project, $assignment] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 0,
            actualHours: 0,
        );
        $project->forceFill(['basis_uurtarief' => 45])->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('8u')
            ->assertDontSee('+8u');

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '12:00',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('4u')
            ->assertDontSee('+4u')
            ->assertDontSee('+8u');
    }

    public function test_internal_pdf_shows_labor_and_client_pdf_hides_it(): void
    {
        $user = User::factory()->create();
        [$project] = $this->makeScheduledProject();
        $project->forceFill(['basis_uurtarief' => 45])->save();

        $this->actingAs($user)
            ->get(route('planning.export', [
                'week' => '2026-09-07',
                'project_id' => $project->id,
            ]))
            ->assertOk()
            ->assertDontSee('€45/u', false)
            ->assertDontSee('€1.800 arbeid', false)
            ->assertDontSee('Uren gepland')
            ->assertDontSee('€/m²');

        $this->actingAs($user)
            ->get(route('planning.export', [
                'week' => '2026-09-07',
                'project_id' => $project->id,
                'intern' => 1,
            ]))
            ->assertOk()
            ->assertSee('Ingepland uren')
            ->assertSee('Budget over')
            ->assertSee('48u')
            ->assertSee('+8u')
            ->assertSee('€/m²')
            ->assertSee('Prognose €/m²')
            ->assertDontSee('48u · €45/u · €1.800 arbeid · €7,50/m²', false);
    }

    public function test_planner_saves_budget_hours_per_work_item(): void
    {
        $user = User::factory()->create();
        [$project, $assignment, $item] = $this->makeScheduledProject();
        $project->forceFill(['basis_uurtarief' => 45])->save();

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->patch(route('projects.update', $project), [
                'basis_uurtarief' => '45,00',
                'work_items' => [
                    $item->id => [
                        'begrote_uren' => '80',
                        'begrote_hoeveelheid' => '600',
                        'uurtarief' => '',
                    ],
                ],
            ])
            ->assertRedirect(route('projects.show', $project));

        $item->refresh();
        $this->assertSame('80.00', $item->begrote_uren);
        $this->assertSame('600.00', $item->begrote_hoeveelheid);
        $this->assertNull($item->uurtarief);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Begroot 80u | Ingepland 48u | Gemaakt 40u | Budget over 40u')
            ->assertDontSee('Linoleum | Begroot 80u | Ingepland 48u | Gemaakt 40u | Budget over 40u')
            ->assertDontSee('48 / 80 uur');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Begroot')
            ->assertSee('80u')
            ->assertSee('48u')
            ->assertSee('40u');
    }

    public function test_planning_board_shows_hour_bar_and_keeps_hours_on_the_scheduled_work(): void
    {
        $user = User::factory()->create();
        [$project, $assignment, $linoleum] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 0,
            actualHours: 0,
        );
        $project->forceFill(['basis_uurtarief' => 45])->save();
        $linoleum->forceFill(['begrote_uren' => 80])->save();
        $egaliseren = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 600,
            'begrote_uren' => 40,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('8u')
            ->assertDontSee('+8u')
            ->assertDontSee('Begroot 80u | Gebruikt 8u | Rest 72u | 10%')
            ->assertDontSee('8 / 80 uur')
            ->assertDontSee('0 / 40 uur');

        $this->assertSame($linoleum->id, $assignment->work_item_id);
        $this->assertNotSame($egaliseren->id, $assignment->work_item_id);
    }

    public function test_planning_board_marks_hour_overrun_in_the_rest_column(): void
    {
        $user = User::factory()->create();
        [$project, $assignment, $item] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 240,
            actualHours: 14,
        );
        $project->forceFill(['basis_uurtarief' => 45])->save();
        $item->forceFill(['begrote_uren' => 8])->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Overschreden')
            ->assertSee('+6u')
            ->assertSee('plan-cell--labor-over', false)
            ->assertSee('title="Uren overschreden"', false);
    }

    public function test_planning_board_warns_when_planned_hours_exceed_budget_before_work_starts(): void
    {
        $user = User::factory()->create();
        [$project, $assignment, $item] = $this->makeScheduledProject(
            people: 3,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '14:00:00',
            completedM2: 0,
            actualHours: 0,
        );
        $project->forceFill(['basis_uurtarief' => 45])->save();
        $item->forceFill([
            'name' => 'Tapijt',
            'begrote_uren' => 15,
        ])->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Tapijt')
            ->assertSee('15u')
            ->assertSee('18u')
            ->assertSee('0u')
            ->assertSee('+3u')
            ->assertSee('plan-cell--labor-over', false)
            ->assertSee('Begroot: 15u', false)
            ->assertSee('Overschrijding: +3u', false)
            ->assertDontSee('Overschreden');

        $this->assertSame($assignment->work_item_id, $item->id);
    }

    public function test_planning_bar_keeps_worker_color_and_hatches_only_the_budget_overflow(): void
    {
        $user = User::factory()->create();
        [$project, $assignment, $item] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-11',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 0,
            actualHours: 0,
        );
        $project->forceFill(['basis_uurtarief' => 45])->save();
        $item->forceFill([
            'name' => 'Primen & Egaliseren',
            'begrote_uren' => 30.1,
        ])->save();

        $color = $assignment->load('worker')->worker->planColor();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('40u')
            ->assertSee('background: '.$color, false)
            ->assertSee('person-bar--over', false)
            ->assertSee('person-bar-overrun', false)
            ->assertSee('left: 75.25%', false)
            ->assertSee('⚠ +9,9u boven begrote uren', false)
            ->assertDontSee('linear-gradient(to right,', false)
            ->assertDontSee('background: var(--color-nicon-danger)', false)
            ->assertSee('plan-cell--labor-over', false);

        $pdf = $this->actingAs($user)
            ->get(route('planning.export', [
                'week' => '2026-09-07',
                'project_id' => $project->id,
                'intern' => 1,
            ]))
            ->assertOk()
            ->assertSee('bar-overrun', false)
            ->assertSee('left: 75.25%', false)
            ->assertDontSee('linear-gradient(to right,', false)
            ->getContent();
        $this->assertSame(1, preg_match('/class="bar is-over"\s+style="background:\s*(#[0-9a-fA-F]{6});/', $pdf, $barColor));
        $printColor = strtolower($barColor[1]);
        $this->assertContains($printColor, ['#9f1239', '#b91c1c', '#7f1d1d', '#be123c', '#881337', '#a1122a']);
        $this->assertNotSame(strtolower($color), $printColor);
        $this->assertStringContainsString('class="swatch" style="background: '.$printColor.'"', $pdf);
        $this->assertStringContainsString('class="bar-overrun" style="left: 75.25%"', $pdf);
        $this->assertDoesNotMatchRegularExpression('/class="bar is-over"[^>]*style="[^"]*repeating-linear-gradient/', $pdf);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertIsString($css);
        $this->assertMatchesRegularExpression(
            '/\.person-bar-overrun\s*\{[^}]*repeating-linear-gradient/s',
            $css,
        );

        $this->assertSame($assignment->work_item_id, $item->id);
    }

    public function test_planning_board_keeps_actual_remaining_hours_after_partial_work(): void
    {
        $user = User::factory()->create();
        [$project, $assignment, $item] = $this->makeScheduledProject(
            people: 3,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '14:00:00',
            completedM2: 40,
            actualHours: 10,
        );
        $project->forceFill(['basis_uurtarief' => 45])->save();
        $item->forceFill([
            'name' => 'Tapijt',
            'begrote_uren' => 15,
        ])->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('10u')
            ->assertSee('5u')
            ->assertSee('+3u')
            ->assertSee('Begroot: 15u', false)
            ->assertSee('Overschrijding: +3u', false)
            ->assertDontSee('Overschreden');

        $this->assertSame($assignment->work_item_id, $item->id);
    }

    public function test_planning_board_marks_low_remaining_hours_in_orange(): void
    {
        $user = User::factory()->create();
        [$project, $assignment, $item] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 240,
            actualHours: 68,
        );
        $project->forceFill(['basis_uurtarief' => 45])->save();
        $item->forceFill(['begrote_uren' => 80])->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('12u')
            ->assertSee('plan-cell--labor-warn', false)
            ->assertDontSee('-12u ⚠');
    }

    public function test_planning_board_shows_planned_hours_on_each_work_row(): void
    {
        $user = User::factory()->create();
        [$project] = $this->makeScheduledProject(
            people: 1,
            start: '2026-09-07',
            end: '2026-09-07',
            startTime: '08:00:00',
            endTime: '16:00:00',
            completedM2: 0,
            actualHours: 0,
        );
        $project->forceFill(['basis_uurtarief' => 45])->save();
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 600,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('8u')
            ->assertDontSee('+8u')
            ->assertSee('Linoleum')
            ->assertDontSee('Gepland 8u')
            ->assertDontSee('Begroot 80u');
    }

    public function test_vakman_does_not_see_labor_costs_on_the_planning_board(): void
    {
        [$project, $assignment] = $this->makeScheduledProject();
        $project->forceFill(['basis_uurtarief' => 45])->save();
        $vakman = User::factory()->vakman($assignment->worker_id)->create();

        $this->actingAs($vakman)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertDontSee('Uren gepland')
            ->assertDontSee('€/m²')
            ->assertDontSee('plan-board--labor', false)
            ->assertDontSee('€45/u', false)
            ->assertDontSee('€1.800 arbeid', false)
            ->assertDontSee('€ 7,50')
            ->assertDontSee('+8u')
            ->assertDontSee('data-labor-fold', false)
            ->assertDontSee('Urenkolommen invouwen')
            ->assertDontSee('>Gepland</div>', false)
            ->assertDontSee('>Gemaakt</div>', false);

        $this->actingAs($vakman)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('€7,50/m²', false)
            ->assertDontSee('Tarief: €45/u', false);

        $this->actingAs($vakman)
            ->get(route('planning.export', [
                'week' => '2026-09-07',
                'project_id' => $project->id,
                'intern' => 1,
            ]))
            ->assertOk()
            ->assertDontSee('Uren gepland')
            ->assertDontSee('€45/u', false);
    }

    public function test_does_not_save_budget_hours_for_another_project_work_item(): void
    {
        $user = User::factory()->create();
        [$project] = $this->makeScheduledProject();
        $other = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $project->customer_id,
            'name' => 'Ander werk',
            'status' => ProjectStatus::Gepland,
        ]);
        $foreign = WorkItem::query()->create([
            'project_id' => $other->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'begrote_uren' => 10,
        ]);

        $this->actingAs($user)
            ->patch(route('projects.update', $project), [
                'work_items' => [
                    (string) $foreign->id => [
                        'begrote_uren' => '999',
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertSame('10.00', $foreign->fresh()->begrote_uren);
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

    /**
     * @return array{0: Project, 1: WorkerAssignment, 2: WorkItem}
     */
    private function makeScheduledProject(
        int $people = 2,
        string $start = '2026-09-07',
        string $end = '2026-09-09',
        string $startTime = '08:00:00',
        string $endTime = '16:00:00',
        float $completedM2 = 240,
        float $actualHours = 40,
    ): array {
        $worker = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'specialty' => 'Linoleum, PVC, Tapijt, Coating',
            'active' => true,
        ]);
        $project = $this->makeProject();
        $project->forceFill([
            'status' => ProjectStatus::InUitvoering,
            'planned_start_date' => $start,
            'planned_end_date' => $end,
        ])->save();
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 1000,
            'planned_start_date' => $start,
            'planned_end_date' => $end,
            'status' => 'in_uitvoering',
        ]);
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'people_count' => $people,
        ]);
        $assignment->applySchedule(
            Carbon::parse($start),
            Carbon::parse($end),
            $startTime,
            $endTime,
        );
        $assignment->save();

        if ($completedM2 > 0 || $actualHours > 0) {
            WorkProgressEntry::query()->create([
                'project_id' => $project->id,
                'work_item_id' => $item->id,
                'date' => $start,
                'completed_quantity' => $completedM2,
                'unit' => 'm2',
                'worked_hours' => $actualHours,
            ]);
        }

        return [$project, $assignment, $item];
    }
}
