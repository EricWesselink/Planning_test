<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningWorkChoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_choices_list_only_the_selected_projects_board_works(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $amersfoort = $this->project($customer, '11P260141', 'Laakse Tuinen Amersfoort');
        $wezep = $this->project($customer, '11P260200', 'School Wezep');
        $this->workItem($amersfoort, 'Primen & Egaliseren', 3168);
        $marmoleum = $this->workItem($amersfoort, 'Marmoleum Cocoa, Linoleum', 100);
        $linoleum = $this->workItem($amersfoort, 'Linoleum', 3168);
        $this->workItem($amersfoort, 'PU gietvloer', 252);
        $this->workItem($amersfoort, 'Entreemat', 21);
        $this->workItem($amersfoort, 'Plinten', 2973, 'm1');
        $this->workItem($amersfoort, 'Vinyl', 0);
        $tapijt = $this->workItem($wezep, 'Tapijt', 80);

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->getContent();
        $choices = $this->choices($html, $amersfoort->id);
        $wezepChoices = $this->choices($html, $wezep->id);

        $this->assertEqualsCanonicalizing([
            'Primen & Egaliseren',
            'Linoleum',
            'Gietvloer',
            'Entreemat',
            'Plinten',
        ], array_column($choices, 'name'));
        $linoleumChoice = collect($choices)->firstWhere('name', 'Linoleum');
        $this->assertIsArray($linoleumChoice);
        $this->assertSame($linoleum->id, $linoleumChoice['id']);
        $this->assertEqualsCanonicalizing([$linoleum->id, $marmoleum->id], $linoleumChoice['member_ids']);
        $this->assertNotContains($tapijt->id, collect($choices)->flatMap(fn (array $choice): array => $choice['member_ids'])->all());
        $this->assertNotContains('Tapijt', array_column($choices, 'name'));
        $this->assertNotContains('Vinyl', array_column($choices, 'name'));
        $this->assertSame(['Tapijt'], array_column($wezepChoices, 'name'));
        $this->assertNotContains($linoleum->id, collect($wezepChoices)->flatMap(fn (array $choice): array => $choice['member_ids'])->all());
    }

    public function test_budgeted_board_lines_stay_choosable_without_ordered_quantity(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Stichting Philadelphia Zorg']);
        $project = $this->project($customer, 'offerte1IP250575', 'Kampen Philadelphia Kaarsenmakerij');
        $removal = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Vloer verwijderen',
            'unit' => 'm2',
            'ordered_quantity' => 0,
            'begrote_uren' => 40,
            'begrote_hoeveelheid' => 790,
            'status' => 'gepland',
        ]);
        $this->workItem($project, 'Rubber tegels', 0);
        $other = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Overig vloerwerk',
            'unit' => 'm2',
            'ordered_quantity' => 0,
            'begrote_uren' => 8,
            'status' => 'gepland',
        ]);
        $work = $this->workItem($project, 'Werk', 578);

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-21', 'project_id' => $project->id]))
            ->assertOk()
            ->getContent();
        $names = array_column($this->choices($html, $project->id), 'name');

        $this->assertContains('Vloer verwijderen', $names);
        $this->assertContains('Overig vloerwerk', $names);
        $this->assertContains('Werk', $names);
        $this->assertNotContains('Rubber tegels', $names);
        $choice = collect($this->choices($html, $project->id))->firstWhere('name', 'Vloer verwijderen');
        $this->assertSame($removal->id, $choice['id']);
        $this->assertContains($removal->id, $choice['member_ids']);
        $this->assertContains($other->id, collect($this->choices($html, $project->id))->firstWhere('name', 'Overig vloerwerk')['member_ids']);
        $this->assertContains($work->id, collect($this->choices($html, $project->id))->firstWhere('name', 'Werk')['member_ids']);
    }

    public function test_saving_an_existing_assignment_keeps_the_planned_work(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = $this->project($customer, '11P260141', 'Laakse Tuinen Amersfoort');
        $marmoleum = $this->workItem($project, 'Marmoleum Cocoa, Linoleum', 100);
        $this->workItem($project, 'Linoleum', 3168);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $marmoleum->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-09',
            'hours_per_day' => 8,
        ]);

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-shift-id="'.$assignment->id.'"', $html);
        $this->assertStringContainsString('data-work-item-id="'.$marmoleum->id.'"', $html);
        $this->assertSame(1, WorkerAssignment::query()->count());

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $marmoleum->id,
                'work_item_ids' => [$marmoleum->id],
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-09',
                'hours' => 8,
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame(1, WorkerAssignment::query()->count());
        $this->assertSame($project->id, $assignment->project_id);
        $this->assertSame($marmoleum->id, $assignment->work_item_id);
        $this->assertSame('2026-09-08', $assignment->start_date->toDateString());
        $this->assertSame('2026-09-09', $assignment->end_date->toDateString());
        $this->assertTrue($assignment->coversWorkIds([$marmoleum->id]));
    }

    private function project(Customer $customer, string $number, string $name): Project
    {
        return Project::query()->create([
            'project_number' => $number,
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
    }

    private function workItem(Project $project, string $name, float $quantity, string $unit = 'm2'): WorkItem
    {
        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'unit' => $unit,
            'ordered_quantity' => $quantity,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function choices(string $html, int $projectId): array
    {
        $this->assertSame(1, preg_match("/data-work-items='([^']*)'/", $html, $matches));
        $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);
        $this->assertIsArray($decoded);
        $items = $decoded[$projectId] ?? $decoded[(string) $projectId] ?? null;
        $this->assertIsArray($items);

        return $items;
    }
}
