<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Enums\SmallWorkType;
use App\Enums\UserRole;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use App\Services\SmallWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmallWorkTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_new_small_work(): void
    {
        $this->get(route('projects.small.create'))->assertRedirect(route('login'));
        $this->post(route('projects.small.store'), [])->assertRedirect(route('login'));
        $this->patch(route('projects.small.update', $this->makeService()), [])->assertRedirect(route('login'));
        $this->get(route('projects.small.werkbon', 1))->assertRedirect(route('login'));
        $this->get(route('projects.small.werkbon.pdf', 1))->assertRedirect(route('login'));
        $this->delete(route('projects.small.attachments.destroy', [1, 1]))->assertRedirect(route('login'));
    }

    public function test_uitvoerder_cannot_open_or_create_small_work(): void
    {
        $user = User::factory()->uitvoerder()->create();

        $this->actingAs($user)->get(route('projects.small.create'))->assertForbidden();
        $this->actingAs($user)->post(route('projects.small.store'), $this->payload())->assertForbidden();
        $this->actingAs($user)->patch(route('projects.small.update', $this->makeService()), [
            'customer_name' => 'Gemeente Deventer',
            'description' => 'plint herstellen',
            'location' => 'Deventer',
            'date' => '2026-09-11',
            'hours' => 4,
        ])->assertForbidden();
    }

    public function test_planner_creates_a_compact_service_row_on_the_planning_board(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();

        $response = $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Service->value,
            'customer_name' => 'Gemeente Deventer',
            'description' => 'plint herstellen',
            'address' => 'Keizerstraat 12',
            'postal_code' => '7411 HD',
            'location' => 'Deventer',
            'date' => '2026-09-08',
            'hours' => 2,
            'worker_id' => $worker->id,
        ]);

        $project = Project::query()->where('kind', ProjectKind::Service)->first();
        $this->assertNotNull($project);
        $response->assertRedirect(route('planning', [
            'week' => '2026-09-07',
            'project_id' => $project->id,
        ]));

        $this->assertSame('plint herstellen', $project->name);
        $this->assertSame('Keizerstraat 12', $project->address);
        $this->assertSame('7411 HD', $project->postal_code);
        $this->assertSame('Deventer', $project->city);
        $this->assertSame('Keizerstraat 12, 7411 HD Deventer', $project->nawLine());
        $this->assertSame(1, $project->workItems()->count());
        $item = $project->workItems()->first();
        $this->assertSame(WorkUnit::Hours, $item?->unit);
        $this->assertSame(2.0, (float) $item?->begrote_uren);
        $this->assertSame('48.00', $project->basis_uurtarief);
        $this->assertSame('48.00', $item?->uurtarief);
        $this->assertFalse((bool) $item?->is_extra_work);

        $this->assertDatabaseHas('worker_assignments', [
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'worker_id' => $worker->id,
            'hours_per_day' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('plan-line--small', false)
            ->assertSee('>SERVICE</span>', false)
            ->assertSee('Deventer – plint herstellen')
            ->assertSee('| 2u')
            ->assertSee('period-marker--start', false)
            ->assertSee('▶ Start', false)
            ->assertSee('08-09-2026');

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('€48/u', false)
            ->assertSee('€96')
            ->assertSee('Keizerstraat 12')
            ->assertSee('7411 HD')
            ->assertSee('Navigeren in Google Maps');
    }

    public function test_planner_creates_service_without_a_craftsman(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Service->value,
            'customer_name' => 'Gemeente Deventer',
            'description' => 'hestel schoon maken',
            'location' => 'Deventer',
            'date' => '2026-09-11',
            'hours' => 4,
        ])->assertRedirect();

        $project = Project::query()->where('kind', ProjectKind::Service)->first();
        $this->assertNotNull($project);
        $this->assertSame(0, $project->assignments()->count());

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('>SERVICE</span>', false)
            ->assertSee('Deventer – hestel schoon maken')
            ->assertSee('| 4u')
            ->assertSee('plan-missing-craftsman', false)
            ->assertSee('period-marker--start', false)
            ->assertSee('▶ Start', false)
            ->assertSee('11-09-2026')
            ->assertSee('data-hours="4"', false)
            ->assertDontSee('Klaar 11-09-2026');
    }

    public function test_planner_creates_standalone_klein_werk_with_a_work_address(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();

        $response = $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Klein->value,
            'customer_name' => 'Gemeente Deventer',
            'description' => '25 m² PVC',
            'address' => 'Keizerstraat 12',
            'postal_code' => '7411 HD',
            'location' => 'Deventer',
            'date' => '2026-09-08',
            'hours' => 8,
            'worker_id' => $worker->id,
        ]);

        $project = Project::query()->where('kind', ProjectKind::Klein)->first();
        $this->assertNotNull($project);
        $response->assertRedirect(route('planning', [
            'week' => '2026-09-07',
            'project_id' => $project->id,
        ]));

        $this->assertSame('25 m² PVC', $project->name);
        $this->assertSame('Keizerstraat 12', $project->address);
        $this->assertSame('7411 HD', $project->postal_code);
        $this->assertSame('Deventer', $project->city);
        $this->assertSame(1, $project->workItems()->count());
        $item = $project->workItems()->first();
        $this->assertFalse((bool) $item?->is_extra_work);

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('>KLEIN</span>', false)
            ->assertSee('Deventer – 25 m² PVC')
            ->getContent();
        $this->assertStringNotContainsString(
            '/projecten/'.$project->id.'/extra-werk/'.$item->id,
            $html
        );
    }

    public function test_new_small_work_form_lists_existing_assignment_numbers(): void
    {
        $user = User::factory()->create();
        $parent = $this->makeConstruction('Gezondheidscentrum Laren');
        $parent->forceFill([
            'project_number' => '250100010',
            'notes' => 'Referentie: 11P241267 Gezondheidscentrum Laren',
        ])->save();

        $this->actingAs($user)
            ->get(route('projects.small.create'))
            ->assertOk()
            ->assertSee('Opdrachtnummer / klant')
            ->assertSee('11P241267')
            ->assertSee('250100010')
            ->assertSee('Gezondheidscentrum Laren')
            ->assertSee('Adres')
            ->assertSee('Postcode')
            ->assertSee('Plaats')
            ->assertSee('Klaar')
            ->assertSee('Egaliseren')
            ->assertSee('Materiaal')
            ->assertSee('Regel toevoegen')
            ->assertSee('Tekening')
            ->assertSee('Foto’s, PDF’s of tekeningen');
    }

    #[DataProvider('standaloneTypes')]
    public function test_planner_attaches_a_drawing_to_standalone_small_work(SmallWorkType $type, ProjectKind $kind): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $drawing = UploadedFile::fake()->image('plattegrond.png', 40, 30);

        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => $type->value,
            'customer_name' => 'Gemeente Deventer',
            'description' => 'plint herstellen',
            'location' => 'Deventer',
            'date' => '2026-09-08',
            'hours' => 4,
            'attachments' => [$drawing],
        ])->assertRedirect();

        $project = Project::query()->where('kind', $kind)->first();
        $this->assertNotNull($project);
        $this->assertSame(1, $project->documents()->where('document_type', SmallWorkService::ATTACHMENT_TYPE)->count());
        $this->assertSame('plattegrond.png', $project->documents()->first()?->original_filename);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('plattegrond.png')
            ->assertSee('Tekeningen');

        $this->actingAs($user)
            ->get(route('projects.small.werkbon', $project))
            ->assertOk()
            ->assertSee('plattegrond.png');
    }

    public function test_rejects_an_unsupported_small_work_drawing(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('projects.small.create'))
            ->post(route('projects.small.store'), [
                'type' => SmallWorkType::Klein->value,
                'customer_name' => 'Gemeente Deventer',
                'description' => '25 m² PVC',
                'location' => 'Deventer',
                'date' => '2026-09-08',
                'hours' => 8,
                'attachments' => [UploadedFile::fake()->create('virus.exe', 20)],
            ])
            ->assertRedirect(route('projects.small.create'))
            ->assertSessionHasErrors(['attachments.0' => 'Alleen foto’s, PDF of tekeningen (JPG, PNG, WebP, GIF, PDF) zijn toegestaan.']);

        $this->assertSame(0, Project::query()->count());
    }

    public function test_planner_adds_and_removes_a_drawing_on_existing_small_work(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $project = $this->makeService();

        $this->actingAs($user)
            ->patch(route('projects.small.update', $project), [
                'customer_name' => 'hegemanbouwgroep',
                'description' => 'hestel schoon maken',
                'location' => 'Deventer',
                'date' => '2026-09-11',
                'hours' => 4,
                'attachments' => [UploadedFile::fake()->image('tekening.jpg', 40, 30)],
            ])
            ->assertRedirect(route('projects.show', $project));

        $document = $project->documents()->where('document_type', SmallWorkService::ATTACHMENT_TYPE)->first();
        $this->assertNotNull($document);
        $this->assertSame('tekening.jpg', $document->original_filename);

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->delete(route('projects.small.attachments.destroy', [$project, $document]))
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHas('status', 'Tekening verwijderd.');

        $this->assertSame(0, $project->documents()->count());
    }

    public function test_uitvoerder_cannot_delete_a_small_work_drawing(): void
    {
        $project = $this->makeService();
        $document = $project->documents()->create([
            'document_type' => SmallWorkService::ATTACHMENT_TYPE,
            'original_filename' => 'tekening.png',
            'file_path' => 'projects/'.$project->id.'/bijlage/tekening.png',
            'mime_type' => 'image/png',
        ]);
        $user = User::factory()->uitvoerder()->create();

        $this->actingAs($user)
            ->delete(route('projects.small.attachments.destroy', [$project, $document]))
            ->assertForbidden();

        $this->assertModelExists($document);
    }

    public function test_extra_work_requires_an_existing_project(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();

        $this->actingAs($user)
            ->from(route('projects.small.create'))
            ->post(route('projects.small.store'), [
                'type' => SmallWorkType::Extra->value,
                'description' => 'extra egaliseren',
                'date' => '2026-09-08',
                'hours' => 4,
                'worker_id' => $worker->id,
            ])
            ->assertRedirect(route('projects.small.create'))
            ->assertSessionHasErrors(['project_id' => 'Kies een bestaand opdrachtnummer.']);

        $this->assertSame(0, WorkItem::query()->where('is_extra_work', true)->count());
    }

    public function test_klein_werk_requires_a_customer_and_place(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('projects.small.create'))
            ->post(route('projects.small.store'), [
                'type' => SmallWorkType::Klein->value,
                'description' => '25 m² PVC',
                'date' => '2026-09-08',
                'hours' => 8,
            ])
            ->assertRedirect(route('projects.small.create'))
            ->assertSessionHasErrors([
                'customer_name' => 'Vul een klantnaam in.',
                'location' => 'Vul een plaats in.',
            ]);

        $this->assertSame(0, Project::query()->where('kind', ProjectKind::Klein)->count());
    }

    public function test_extra_work_stays_on_the_parent_project_and_shows_as_a_compact_row(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();
        $parent = $this->makeConstruction('Gezondheidscentrum Laren');

        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Extra->value,
            'project_id' => $parent->id,
            'description' => 'extra egaliseren',
            'date' => '2026-09-09',
            'hours' => 4,
            'worker_id' => $worker->id,
        ])->assertRedirect(route('planning', [
            'week' => '2026-09-07',
            'project_id' => $parent->id,
        ]));

        $this->assertSame(1, Project::query()->count());
        $extra = $parent->workItems()->where('is_extra_work', true)->first();
        $this->assertNotNull($extra);
        $this->assertSame('extra egaliseren', $extra->name);
        $this->assertSame(4.0, (float) $extra->begrote_uren);
        $this->assertSame('48.00', $extra->uurtarief);
        $this->assertDatabaseHas('worker_assignments', [
            'project_id' => $parent->id,
            'work_item_id' => $extra->id,
            'hours_per_day' => 4,
        ]);

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('>EXTRA</span>', false)
            ->assertSee('Gezondheidscentrum Laren – extra egaliseren')
            ->assertSee('| 4u')
            ->assertSee('Extra werk 4u (niet in oorspronkelijke begroting)')
            ->getContent();

        $this->assertStringContainsString('plan-line--small', $html);
        $this->assertStringContainsString('period-marker--start', $html);
        $this->assertStringContainsString('09-09-2026', $html);
    }

    public function test_extra_work_without_a_craftsman_still_shows_the_start_date(): void
    {
        $user = User::factory()->create();
        $parent = $this->makeConstruction('Gezondheidscentrum Laren');

        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Extra->value,
            'project_id' => $parent->id,
            'description' => 'extra egaliseren',
            'date' => '2026-09-09',
            'hours' => 4,
        ])->assertRedirect();

        $this->assertSame(0, $parent->assignments()->count());

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('>EXTRA</span>', false)
            ->assertSee('Gezondheidscentrum Laren – extra egaliseren')
            ->assertSee('period-marker--start', false)
            ->assertSee('▶ Start', false)
            ->assertSee('09-09-2026')
            ->assertSee('plan-missing-craftsman', false);
    }

    public function test_extra_work_name_opens_the_extra_work_form_instead_of_the_drawing(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();
        $parent = $this->makeConstruction('Gezondheidscentrum Laren');

        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Extra->value,
            'project_id' => $parent->id,
            'description' => 'vloer herstel',
            'date' => '2026-09-09',
            'hours' => 8,
            'worker_id' => $worker->id,
        ])->assertRedirect();

        $extra = $parent->workItems()->where('is_extra_work', true)->first();
        $this->assertNotNull($extra);

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('projects.extra.edit', [$parent, $extra], false),
            $html
        );

        $this->actingAs($user)
            ->get(route('projects.extra.edit', [$parent, $extra]))
            ->assertOk()
            ->assertSee('Klaar')
            ->assertSee('Geworden uren')
            ->assertSee('Egaliseren')
            ->assertSee('Materiaal')
            ->assertSee('Regel toevoegen')
            ->assertSee('vloer herstel');

        $this->actingAs($user)
            ->get('/projecten/'.$parent->id.'/extra-werk/'.$extra->id)
            ->assertOk()
            ->assertSee('vloer herstel');

        $this->actingAs($user)
            ->get(route('projects.show', $parent))
            ->assertOk()
            ->assertSee('EXTRA vloer herstel — klaar, uren en materiaal');
    }

    public function test_planner_saves_klaar_date_actual_hours_and_material_on_extra_work(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();
        $parent = $this->makeConstruction('Gezondheidscentrum Laren');

        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Extra->value,
            'project_id' => $parent->id,
            'description' => 'vloer herstel',
            'date' => '2026-09-09',
            'hours' => 8,
            'worker_id' => $worker->id,
        ])->assertRedirect();

        $extra = $parent->workItems()->where('is_extra_work', true)->first();
        $this->assertNotNull($extra);

        $this->actingAs($user)
            ->patch(route('projects.extra.update', [$parent, $extra]), [
                'description' => 'vloer herstel',
                'date' => '2026-09-09',
                'klaar_date' => '2026-09-11',
                'hours' => 8,
                'quantity' => 18,
                'actual_hours' => 10,
                'completed_quantity' => 18,
            ])
            ->assertRedirect(route('planning', [
                'week' => '2026-09-07',
                'project_id' => $parent->id,
            ]));

        $extra->refresh();
        $this->assertSame(WorkUnit::SquareMeter, $extra->unit);
        $this->assertSame(18.0, (float) $extra->ordered_quantity);
        $this->assertSame('2026-09-11', $extra->planned_end_date?->toDateString());
        $this->assertSame('gereed', $extra->status);
        $this->assertSame(10.0, (float) $extra->progressEntries()->sum('worked_hours'));

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('18 m²')
            ->assertSee('10u')
            ->assertSee('Klaar 11-09-2026');
    }

    public function test_planner_saves_egaliseren_and_material_lines_on_extra_work(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();
        $parent = $this->makeConstruction('Gezondheidscentrum Laren');

        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Extra->value,
            'project_id' => $parent->id,
            'description' => 'vloer herstel',
            'date' => '2026-09-09',
            'hours' => 8,
            'worker_id' => $worker->id,
        ])->assertRedirect();

        $extra = $parent->workItems()->where('is_extra_work', true)->first();
        $this->assertNotNull($extra);

        $this->actingAs($user)
            ->patch(route('projects.extra.update', [$parent, $extra]), [
                'description' => 'vloer herstel',
                'date' => '2026-09-09',
                'klaar_date' => '2026-09-11',
                'hours' => 8,
                'actual_hours' => 10,
                'lines' => [
                    ['name' => 'Egaliseren', 'quantity' => 30, 'completed' => 30],
                    ['name' => 'Marmoleum', 'quantity' => 30, 'completed' => 30],
                ],
            ])
            ->assertRedirect(route('planning', [
                'week' => '2026-09-07',
                'project_id' => $parent->id,
            ]));

        $extra->refresh();
        $this->assertSame(WorkUnit::SquareMeter, $extra->unit);
        $this->assertSame(60.0, (float) $extra->ordered_quantity);
        $this->assertSame([
            ['name' => 'Egaliseren', 'quantity' => 30.0, 'completed' => 30.0],
            ['name' => 'Marmoleum', 'quantity' => 30.0, 'completed' => 30.0],
        ], $extra->extraLines());

        $this->actingAs($user)
            ->get(route('projects.extra.edit', [$parent, $extra]))
            ->assertOk()
            ->assertSee('Egaliseren')
            ->assertSee('Marmoleum')
            ->assertSee('Regel toevoegen');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Egaliseren 30 m² · Marmoleum 30 m²')
            ->assertSee('10u');
    }

    public function test_guest_is_redirected_from_extra_work_form(): void
    {
        $parent = $this->makeConstruction('Gezondheidscentrum Laren');
        $extra = $parent->workItems()->create([
            'name' => 'vloer herstel',
            'unit' => WorkUnit::Hours,
            'ordered_quantity' => 8,
            'begrote_uren' => 8,
            'is_extra_work' => true,
            'small_work_type' => SmallWorkType::Extra,
            'status' => 'gepland',
            'sort_order' => 2,
        ]);

        $this->get(route('projects.extra.edit', [$parent, $extra]))->assertRedirect(route('login'));
        $this->patch(route('projects.extra.update', [$parent, $extra]), [])->assertRedirect(route('login'));
    }

    public function test_extra_work_form_is_not_found_for_regular_work_items(): void
    {
        $user = User::factory()->create();
        $parent = $this->makeConstruction('Gezondheidscentrum Laren');
        $regular = $parent->workItems()->first();
        $this->assertNotNull($regular);

        $this->actingAs($user)
            ->get(route('projects.extra.edit', [$parent, $regular]))
            ->assertNotFound();
    }

    public function test_extra_work_form_is_not_found_for_a_work_item_of_another_project(): void
    {
        $user = User::factory()->create();
        $parent = $this->makeConstruction('Gezondheidscentrum Laren');
        $other = $this->makeConstruction('Laakse Tuinen', '260200091');
        $extra = $other->workItems()->create([
            'name' => 'vloer herstel',
            'unit' => WorkUnit::Hours,
            'ordered_quantity' => 8,
            'begrote_uren' => 8,
            'is_extra_work' => true,
            'small_work_type' => SmallWorkType::Extra,
            'status' => 'gepland',
            'sort_order' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('projects.extra.edit', [$parent, $extra]))
            ->assertNotFound();
    }

    public function test_extra_work_shows_klaar_date_actual_hours_and_material_on_the_planning_board(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();
        $parent = $this->makeConstruction('Gezondheidscentrum Laren');

        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Extra->value,
            'project_id' => $parent->id,
            'description' => 'vloer herstel',
            'date' => '2026-09-09',
            'klaar_date' => '2026-09-11',
            'hours' => 8,
            'quantity' => 18,
            'worker_id' => $worker->id,
        ])->assertRedirect();

        $extra = $parent->workItems()->where('is_extra_work', true)->first();
        $this->assertNotNull($extra);
        $this->assertSame(WorkUnit::SquareMeter, $extra->unit);
        $this->assertSame(18.0, (float) $extra->ordered_quantity);
        $this->assertSame(8.0, (float) $extra->begrote_uren);
        $this->assertSame('2026-09-11', $extra->planned_end_date?->toDateString());

        WorkProgressEntry::query()->create([
            'project_id' => $parent->id,
            'work_item_id' => $extra->id,
            'worker_id' => $worker->id,
            'date' => '2026-09-11',
            'completed_quantity' => 18,
            'unit' => WorkUnit::SquareMeter,
            'worked_hours' => 10,
            'created_by' => $user->id,
        ]);
        $extra->syncStatusFromProgress();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('>EXTRA</span>', false)
            ->assertSee('18 m²')
            ->assertSee('10u')
            ->assertSee('period-marker--end', false)
            ->assertSee('Klaar 11-09-2026')
            ->assertSee('period-marker--end is-done', false);
    }

    public function test_extra_work_rejects_a_klaar_date_before_the_start_date(): void
    {
        $user = User::factory()->create();
        $parent = $this->makeConstruction('Gezondheidscentrum Laren');

        $this->actingAs($user)
            ->from(route('projects.small.create'))
            ->post(route('projects.small.store'), [
                'type' => SmallWorkType::Extra->value,
                'project_id' => $parent->id,
                'description' => 'vloer herstel',
                'date' => '2026-09-09',
                'klaar_date' => '2026-09-08',
                'hours' => 8,
            ])
            ->assertRedirect(route('projects.small.create'))
            ->assertSessionHasErrors(['klaar_date' => 'Klaar moet op dezelfde dag of later vallen dan de startdatum.']);

        $this->assertSame(0, WorkItem::query()->where('is_extra_work', true)->count());
    }

    public function test_planner_updates_service_details_and_moves_the_assignment(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();

        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Service->value,
            'customer_name' => 'hegemanbouwgroep',
            'description' => 'hestel schoon maken',
            'location' => 'Deventer',
            'date' => '2026-09-11',
            'hours' => 4,
            'worker_id' => $worker->id,
        ])->assertRedirect();

        $project = Project::query()->where('kind', ProjectKind::Service)->first();
        $this->assertNotNull($project);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('name="description"', false)
            ->assertSee('hestel schoon maken')
            ->assertSee('hegemanbouwgroep');

        $this->actingAs($user)
            ->patch(route('projects.small.update', $project), [
                'customer_name' => 'Gemeente Apeldoorn',
                'description' => 'plinten herstellen',
                'address' => 'Hoofdstraat 8',
                'postal_code' => '7311 KK',
                'location' => 'Apeldoorn',
                'date' => '2026-09-14',
                'hours' => 6,
                'work_number' => 'KW-77',
            ])
            ->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame('plinten herstellen', $project->name);
        $this->assertSame('Hoofdstraat 8', $project->address);
        $this->assertSame('7311 KK', $project->postal_code);
        $this->assertSame('Apeldoorn', $project->city);
        $this->assertSame('Hoofdstraat 8, 7311 KK Apeldoorn', $project->nawLine());
        $this->assertSame('KW-77', $project->project_number);
        $this->assertSame('2026-09-14', $project->planned_start_date?->toDateString());
        $this->assertSame('Gemeente Apeldoorn', $project->customer?->name);
        $item = $project->workItems()->first();
        $this->assertSame('plinten herstellen', $item?->name);
        $this->assertSame(6.0, (float) $item?->begrote_uren);
        $this->assertSame('2026-09-14', $item?->planned_start_date?->toDateString());

        $assignment = WorkerAssignment::query()->where('project_id', $project->id)->first();
        $this->assertNotNull($assignment);
        $this->assertSame('2026-09-14', $assignment->start_date->toDateString());
        $this->assertSame('2026-09-14', $assignment->end_date->toDateString());
        $this->assertSame(6.0, (float) $assignment->hours_per_day);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('plinten herstellen')
            ->assertSee('Gemeente Apeldoorn')
            ->assertSee('Hoofdstraat 8')
            ->assertSee('Apeldoorn')
            ->assertSee('Navigeren in Google Maps');
    }

    public function test_small_work_update_is_not_found_for_a_full_project(): void
    {
        $user = User::factory()->create();
        $project = $this->makeConstruction('Laakse Tuinen');

        $this->actingAs($user)
            ->patch(route('projects.small.update', $project), [
                'customer_name' => 'Gemeente',
                'description' => 'plint herstellen',
                'location' => 'Deventer',
                'date' => '2026-09-11',
                'hours' => 4,
            ])
            ->assertNotFound();
    }

    public function test_rejects_an_empty_description_when_updating_service(): void
    {
        $user = User::factory()->create();
        $project = $this->makeService();

        $this->actingAs($user)
            ->from(route('projects.show', $project))
            ->patch(route('projects.small.update', $project), [
                'customer_name' => 'hegemanbouwgroep',
                'description' => '',
                'location' => 'Deventer',
                'date' => '2026-09-11',
                'hours' => 4,
            ])
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHasErrors(['description' => 'Vul een korte omschrijving in.']);

        $this->assertSame('hestel schoon maken', $project->fresh()->name);
    }

    public function test_four_hours_of_service_leave_half_a_man_day_free_that_day(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();

        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Service->value,
            'customer_name' => 'Gemeente Deventer',
            'description' => 'plint herstellen',
            'location' => 'Deventer',
            'date' => '2026-09-08',
            'hours' => 4,
            'worker_id' => $worker->id,
        ])->assertRedirect();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Beschikbare mandagen:')
            ->assertSee('4,5');
    }

    public function test_kleine_werken_filter_hides_full_projects(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();
        $this->makeConstruction('Laakse Tuinen');

        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Service->value,
            'customer_name' => 'Gemeente Deventer',
            'description' => 'plint herstellen',
            'location' => 'Deventer',
            'date' => '2026-09-08',
            'hours' => 2,
            'worker_id' => $worker->id,
        ])->assertRedirect();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'kind' => ProjectKind::KLEINE_FILTER]))
            ->assertOk()
            ->assertSee('>Kleine werken</option>', false)
            ->assertSee('>SERVICE</span>', false)
            ->assertSee('Deventer – plint herstellen')
            ->assertDontSee('>Laakse Tuinen</span>', false);
    }

    public function test_rejects_hours_outside_two_to_eight(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();

        $this->actingAs($user)
            ->from(route('projects.small.create'))
            ->post(route('projects.small.store'), [
                'type' => SmallWorkType::Service->value,
                'customer_name' => 'Gemeente Deventer',
                'description' => 'plint herstellen',
                'location' => 'Deventer',
                'date' => '2026-09-08',
                'hours' => 3,
                'worker_id' => $worker->id,
            ])
            ->assertRedirect(route('projects.small.create'))
            ->assertSessionHasErrors(['hours' => 'Kies 2, 4, 6 of 8 uur.']);
    }

    public function test_service_page_offers_servicebon_view_and_pdf(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Service->value,
            'customer_name' => 'Polinder',
            'description' => 'vlekken in het tapijt',
            'address' => 'Straat 12',
            'postal_code' => '7411 HD',
            'location' => 'Kampen',
            'date' => '2026-09-21',
            'hours' => 2,
        ])->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Service)->first();
        $this->assertNotNull($project);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('>Servicebon</a>', false)
            ->assertSee('Download PDF')
            ->assertSee(route('projects.small.werkbon', $project, false), false)
            ->assertSee(route('projects.small.werkbon.pdf', $project, false), false);
    }

    public function test_servicebon_shows_the_job_and_print_download(): void
    {
        $user = User::factory()->create();
        $worker = $this->makeWorker();
        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Service->value,
            'customer_name' => 'Polinder',
            'description' => 'vlekken in het tapijt',
            'address' => 'Straat 12',
            'postal_code' => '7411 HD',
            'location' => 'Kampen',
            'date' => '2026-09-21',
            'hours' => 2,
            'worker_id' => $worker->id,
            'work_number' => '2026-041',
        ])->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Service)->first();
        $this->assertNotNull($project);

        $this->actingAs($user)
            ->get(route('projects.small.werkbon', $project))
            ->assertOk()
            ->assertSee('SERVICEBON')
            ->assertSee('Servicebon')
            ->assertSee('Nicon Vloeren')
            ->assertSee('Kampen – vlekken in het tapijt')
            ->assertSee('Klant Polinder')
            ->assertSee('Straat 12')
            ->assertSee('7411 HD')
            ->assertSee('Werknummer 2026-041')
            ->assertSee('vlekken in het tapijt')
            ->assertSee('2 u')
            ->assertSee('Albert')
            ->assertSee('Vakmannen')
            ->assertSee('Wanneer')
            ->assertSee('21-09-2026')
            ->assertSee('Afdrukken')
            ->assertSee('Download PDF')
            ->assertSee(route('projects.small.werkbon.pdf', $project, false), false)
            ->assertDontSee('€')
            ->assertDontSee('Kloppenburg');
    }

    public function test_servicebon_pdf_downloads(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Service->value,
            'customer_name' => 'Polinder',
            'description' => 'vlekken in het tapijt',
            'location' => 'Kampen',
            'date' => '2026-09-21',
            'hours' => 2,
        ])->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Service)->first();
        $this->assertNotNull($project);

        $response = $this->actingAs($user)->get(route('projects.small.werkbon.pdf', $project));
        $response->assertOk();
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));
        $response->assertDownload();
    }

    public function test_klein_werk_page_offers_a_werkbon_pdf(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('projects.small.store'), [
            'type' => SmallWorkType::Klein->value,
            'customer_name' => 'Gemeente Deventer',
            'description' => '25 m² PVC',
            'location' => 'Deventer',
            'date' => '2026-09-08',
            'hours' => 8,
        ])->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Klein)->first();
        $this->assertNotNull($project);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('>Werkbon</a>', false)
            ->assertDontSee('>Servicebon</a>', false);

        $this->actingAs($user)
            ->get(route('projects.small.werkbon', $project))
            ->assertOk()
            ->assertSee('WERKBON')
            ->assertDontSee('SERVICEBON');
    }

    public function test_construction_project_has_no_small_work_bon(): void
    {
        $user = User::factory()->create();
        $project = $this->makeConstruction('Nieuwbouw');

        $this->actingAs($user)->get(route('projects.small.werkbon', $project))->assertNotFound();
        $this->actingAs($user)->get(route('projects.small.werkbon.pdf', $project))->assertNotFound();
    }

    #[DataProvider('rolesThatMayCreate')]
    public function test_roles_that_may_create_small_work(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('projects.small.create'))->assertOk();
    }

    /** @return array<string, array{0: UserRole}> */
    public static function rolesThatMayCreate(): array
    {
        return [
            'admin' => [UserRole::Admin],
            'planner' => [UserRole::Planner],
            'projectleider' => [UserRole::Projectleider],
        ];
    }

    /** @return array<string, array{0: SmallWorkType, 1: ProjectKind}> */
    public static function standaloneTypes(): array
    {
        return [
            'service' => [SmallWorkType::Service, ProjectKind::Service],
            'klein' => [SmallWorkType::Klein, ProjectKind::Klein],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'type' => SmallWorkType::Service->value,
            'customer_name' => 'Gemeente Deventer',
            'description' => 'plint herstellen',
            'location' => 'Deventer',
            'date' => '2026-09-08',
            'hours' => 2,
            'worker_id' => Worker::query()->create([
                'name' => 'Kees',
                'employment_type' => 'eigen',
                'active' => true,
            ])->id,
        ];
    }

    private function makeWorker(): Worker
    {
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'specialty' => 'PVC',
            'active' => true,
        ]);
        User::factory()->vakman($worker->id)->create(['name' => $worker->name]);

        return $worker;
    }

    private function makeConstruction(string $name, string $projectNumber = '260200090'): Project
    {
        $customer = Customer::query()->create(['name' => 'Gemeente']);
        $project = Project::query()->create([
            'project_number' => $projectNumber,
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'kind' => ProjectKind::Project,
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);

        return $project->fresh('workItems');
    }

    private function makeService(): Project
    {
        $customer = Customer::query()->create(['name' => 'hegemanbouwgroep']);
        $project = Project::query()->create([
            'project_number' => '2026-001',
            'customer_id' => $customer->id,
            'name' => 'hestel schoon maken',
            'city' => 'Deventer',
            'kind' => ProjectKind::Service,
            'status' => 'gepland',
            'planned_start_date' => '2026-09-11',
            'planned_end_date' => '2026-09-11',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'hestel schoon maken',
            'unit' => WorkUnit::Hours,
            'ordered_quantity' => 4,
            'begrote_uren' => 4,
            'planned_start_date' => '2026-09-11',
            'planned_end_date' => '2026-09-11',
            'status' => 'gepland',
            'sort_order' => 1,
        ]);

        return $project->fresh(['customer', 'workItems']);
    }
}
