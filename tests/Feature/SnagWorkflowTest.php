<?php

namespace Tests\Feature;

use App\Enums\SnagPhotoType;
use App\Enums\SnagStatus;
use App\Enums\UserRole;
use App\Models\AreaDrawingMarker;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\SnagItem;
use App\Models\SnagPhoto;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkItem;
use App\Notifications\SnagAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SnagWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_points_on_ground_floor_match_rooms(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $document = $project->plattegrond();
        $this->placeRoom($project, $document, '0.21', 0.22, 0.40);
        $this->placeRoom($project, $document, '0.17', 0.48, 0.55);
        $this->placeRoom($project, $document, '0.24', 0.72, 0.33);

        $this->actingAs($user);
        $this->postSnag($project, $document, $albert, 0.21, 0.41, 'Kim in speellokaal');
        $this->postSnag($project, $document, $albert, 0.49, 0.54, 'Deurhal beschadigd');
        $this->postSnag($project, $document, $albert, 0.71, 0.34, 'Plint spreekruimte');

        $this->assertDatabaseHas('snag_items', [
            'project_id' => $project->id,
            'number' => 1,
            'description' => 'Kim in speellokaal',
            'project_area_id' => $project->areas()->where('area_number', '0.21')->value('id'),
        ]);
        $this->assertDatabaseHas('snag_items', [
            'project_id' => $project->id,
            'number' => 2,
            'project_area_id' => $project->areas()->where('area_number', '0.17')->value('id'),
        ]);
        $this->assertDatabaseHas('snag_items', [
            'project_id' => $project->id,
            'number' => 3,
            'project_area_id' => $project->areas()->where('area_number', '0.24')->value('id'),
        ]);
    }

    public function test_full_route_assign_notify_complete_approve(): void
    {
        Storage::fake('local');
        Notification::fake();
        [$user, $project, $albert] = $this->makeProject();
        $document = $project->plattegrond();
        $this->placeRoom($project, $document, '0.21', 0.22, 0.40);

        $this->actingAs($user)
            ->post(route('projects.snags.store', $project), [
                'x' => 0.22,
                'y' => 0.41,
                'drawing_page' => 1,
                'document_id' => $document->id,
                'description' => 'Kim niet recht',
                'assigned_worker_id' => $albert->id,
                'due_date' => now()->addDays(5)->toDateString(),
                'notify' => '1',
                'photos' => [
                    UploadedFile::fake()->image('constatering.jpg', 640, 480),
                    UploadedFile::fake()->image('detail.jpg', 640, 480),
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('snag.number', 1)
            ->assertJsonPath('snag.status', SnagStatus::Assigned->value)
            ->assertJsonPath('snag.tone', 'assigned');

        $snag = SnagItem::query()->first();
        $this->assertNotEmpty($snag->public_token);
        $this->assertDatabaseCount('snag_photos', 2);
        $this->assertSame(SnagPhotoType::Issue, $snag->photos->first()->photo_type);

        Notification::assertSentOnDemand(SnagAssignedNotification::class, function (SnagAssignedNotification $mail) use ($snag) {
            return $mail->snag->is($snag)
                && str_contains($mail->publicUrl, $snag->public_token)
                && $mail->context === 'assigned';
        });

        $this->post(route('projects.snags.store', $project), [
            'x' => 0.5,
            'y' => 0.5,
            'drawing_page' => 1,
            'description' => '',
            'assigned_worker_id' => $albert->id,
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->get(route('snags.public.show', $snag->public_token))
            ->assertOk()
            ->assertSee('Opleverpunt #1')
            ->assertSee('Kim niet recht')
            ->assertSee('Gereed melden')
            ->assertSee('In behandeling')
            ->assertDontSee('Dashboard');

        $this->post(route('snags.public.progress', $snag->public_token))->assertRedirect();
        $this->assertSame(SnagStatus::InProgress, $snag->fresh()->status);

        $this->post(route('snags.public.comment', $snag->public_token), [
            'note' => 'Kom vanmiddag.',
        ])->assertRedirect();

        $this->post(route('snags.public.complete', $snag->public_token), [
            'note' => 'Hersteld',
            'photos' => [UploadedFile::fake()->image('gereed.jpg', 800, 600)],
        ])->assertRedirect();

        $snag->refresh();
        $this->assertSame(SnagStatus::ReportedDone, $snag->status);
        $this->assertSame(1, $snag->completionPhotos()->count());

        $this->actingAs($user)
            ->get(route('projects.snags.index', $project))
            ->assertOk()
            ->assertSee('Gereed gemeld')
            ->assertSee('Goedkeuren')
            ->assertDontSee('Goedgekeurd')
            ->assertSee('Afgehandeld');

        $this->actingAs($user)
            ->postJson(route('projects.snags.approve', [$project, $snag]))
            ->assertOk()
            ->assertJsonPath('snag.status', SnagStatus::Closed->value)
            ->assertJsonPath('snag.tone', 'done')
            ->assertJsonPath('snag.status_label', 'Afgehandeld');

        $this->assertNotNull($snag->fresh()->approved_at);
        $this->assertNotNull($snag->fresh()->closed_at);
        $this->assertSame($user->id, $snag->fresh()->closed_by);
        $this->assertDatabaseHas('snag_history', [
            'snag_item_id' => $snag->id,
            'action' => 'closed',
            'new_status' => SnagStatus::Closed->value,
            'created_by' => $user->id,
        ]);
    }

    public function test_reject_sends_worker_back_and_keeps_number(): void
    {
        Storage::fake('local');
        Notification::fake();
        [$user, $project, $albert] = $this->makeProject();
        $snag = $this->makeSnag($project, $albert, SnagStatus::ReportedDone, ['number' => 12]);

        $this->actingAs($user)
            ->postJson(route('projects.snags.reject', [$project, $snag]), [
                'note' => 'Nog niet goed',
            ])
            ->assertOk()
            ->assertJsonPath('snag.status', SnagStatus::Assigned->value)
            ->assertJsonPath('snag.number', 12)
            ->assertJsonPath('snag.tone', 'assigned');

        Notification::assertSentOnDemand(SnagAssignedNotification::class, function (SnagAssignedNotification $mail) {
            return $mail->context === 'rework';
        });
    }

    public function test_marker_move_keeps_details(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $snag = $this->makeSnag($project, $albert, SnagStatus::Open, [
            'x' => 0.20,
            'y' => 0.30,
            'description' => 'Zelfde tekst',
        ]);

        $this->actingAs($user)
            ->patchJson(route('projects.snags.update', [$project, $snag]), [
                'x' => 0.61,
                'y' => 0.44,
                'drawing_page' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('snag.x', 0.61)
            ->assertJsonPath('snag.description', 'Zelfde tekst')
            ->assertJsonPath('snag.number', $snag->number);
    }

    public function test_create_ignores_manual_status_from_the_form(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $document = $project->plattegrond();

        $this->actingAs($user)
            ->postJson(route('projects.snags.store', $project), [
                'x' => 0.22,
                'y' => 0.41,
                'drawing_page' => 1,
                'document_id' => $document->id,
                'description' => 'Nieuwe kim',
                'assigned_worker_id' => $albert->id,
                'status' => SnagStatus::Closed->value,
            ])
            ->assertCreated()
            ->assertJsonPath('snag.status', SnagStatus::Open->value)
            ->assertJsonPath('snag.tone', 'open');
    }

    public function test_projectleider_can_manually_set_each_status(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $snag = $this->makeSnag($project, $albert, SnagStatus::Assigned, ['number' => 8]);

        foreach (SnagStatus::cases() as $status) {
            $this->actingAs($user)
                ->patchJson(route('projects.snags.update', [$project, $snag]), [
                    'status' => $status->value,
                ])
                ->assertOk()
                ->assertJsonPath('snag.status', $status->value)
                ->assertJsonPath('snag.tone', $status->tone())
                ->assertJsonPath('snag.status_label', $status->label());

            $this->assertDatabaseHas('snag_history', [
                'snag_item_id' => $snag->id,
                'new_status' => $status->value,
                'created_by' => $user->id,
            ]);
        }

        $closed = $snag->fresh();
        $this->assertSame(SnagStatus::Closed, $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame($user->id, $closed->closed_by);
        $this->assertNotNull($closed->approved_at);

        $this->actingAs($user)
            ->patchJson(route('projects.snags.update', [$project, $snag]), [
                'status' => SnagStatus::Assigned->value,
            ])
            ->assertOk()
            ->assertJsonPath('snag.status', SnagStatus::Assigned->value)
            ->assertJsonPath('snag.tone', 'assigned')
            ->assertJsonPath('snag.status_label', 'Toegewezen');

        $reopened = $snag->fresh();
        $this->assertNull($reopened->closed_at);
        $this->assertNull($reopened->closed_by);
        $this->assertNull($reopened->approved_at);
        $this->assertDatabaseHas('snag_history', [
            'snag_item_id' => $snag->id,
            'action' => 'closed',
            'new_status' => SnagStatus::Closed->value,
            'created_by' => $user->id,
        ]);
        $this->assertDatabaseHas('snag_history', [
            'snag_item_id' => $snag->id,
            'action' => 'status',
            'old_status' => SnagStatus::Closed->value,
            'new_status' => SnagStatus::Assigned->value,
            'created_by' => $user->id,
        ]);
    }

    public function test_status_update_stores_a_completion_photo_when_reported_done(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $snag = $this->makeSnag($project, $albert, SnagStatus::Assigned);

        $this->actingAs($user)
            ->patchJson(route('projects.snags.update', [$project, $snag]), [
                'status' => SnagStatus::ReportedDone->value,
                'photos' => [UploadedFile::fake()->image('gereed.jpg', 800, 600)],
            ])
            ->assertOk()
            ->assertJsonPath('snag.status', SnagStatus::ReportedDone->value)
            ->assertJsonPath('snag.photos.0.type', SnagPhotoType::Completion->value)
            ->assertJsonPath('snag.photos.0.type_label', 'Gereedfoto');

        $this->assertSame(1, $snag->fresh()->photos()->count());
        $this->assertSame(SnagPhotoType::Completion, $snag->photos()->first()->photo_type);
    }

    public function test_photo_can_be_added_with_another_status_change(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $snag = $this->makeSnag($project, $albert, SnagStatus::Assigned);

        $this->actingAs($user)
            ->patchJson(route('projects.snags.update', [$project, $snag]), [
                'status' => SnagStatus::InProgress->value,
                'photos' => [UploadedFile::fake()->image('bezig.jpg', 800, 600)],
            ])
            ->assertOk()
            ->assertJsonPath('snag.status', SnagStatus::InProgress->value)
            ->assertJsonPath('snag.photos.0.type', SnagPhotoType::Issue->value)
            ->assertJsonPath('snag.photos.0.type_label', 'Constatering');

        $this->assertSame(SnagPhotoType::Issue, $snag->photos()->first()->photo_type);
    }

    public function test_photo_can_be_added_without_changing_status(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $snag = $this->makeSnag($project, $albert, SnagStatus::ReportedDone);

        $this->actingAs($user)
            ->patchJson(route('projects.snags.update', [$project, $snag]), [
                'photos' => [UploadedFile::fake()->image('gereed-2.jpg', 800, 600)],
            ])
            ->assertOk()
            ->assertJsonPath('snag.status', SnagStatus::ReportedDone->value)
            ->assertJsonPath('snag.photos.0.type', SnagPhotoType::Completion->value);

        $this->assertSame(SnagStatus::ReportedDone, $snag->fresh()->status);
    }

    public function test_planner_can_reassign_snag_worker(): void
    {
        Storage::fake('local');
        [, $project, $albert] = $this->makeProject();
        $planner = User::factory()->create();
        $jansen = Worker::query()->create([
            'name' => 'Jansen',
            'company' => 'Jansen Vloeren',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $snag = $this->makeSnag($project, $albert, SnagStatus::Open);

        $this->actingAs($planner)
            ->patchJson(route('projects.snags.update', [$project, $snag]), [
                'assigned_worker_id' => $jansen->id,
            ])
            ->assertOk()
            ->assertJsonPath('snag.assigned_worker_id', $jansen->id)
            ->assertJsonPath('snag.worker', 'ZZP Jansen Vloeren');

        $this->assertSame($jansen->id, $snag->fresh()->assigned_worker_id);
        $this->assertDatabaseHas('snag_history', [
            'snag_item_id' => $snag->id,
            'action' => 'assigned',
            'worker_id' => $jansen->id,
            'created_by' => $planner->id,
        ]);
    }

    public function test_list_filters_and_pdf_and_drawing_deeplink(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $open = $this->makeSnag($project, $albert, SnagStatus::Open, ['number' => 1]);
        $this->makeSnag($project, $albert, SnagStatus::Closed, ['number' => 2, 'description' => 'Klaar werk']);

        $this->actingAs($user)
            ->get(route('projects.snags.index', ['project' => $project, 'status' => 'open']))
            ->assertOk()
            ->assertSee((string) $open->number)
            ->assertDontSee('Klaar werk');

        $this->actingAs($user)
            ->get(route('projects.snags.export', ['project' => $project, 'status' => 'open_all', 'photos' => 1, 'drawing' => 1]))
            ->assertOk()
            ->assertSee('Opleverpunt')
            ->assertSee($project->name);

        $this->actingAs($user)
            ->get(route('projects.show', ['project' => $project, 'snag' => $open->id]))
            ->assertOk()
            ->assertSee('data-open-snag="'.$open->id.'"', false)
            ->assertSee('Opslaan &amp; versturen', false)
            ->assertSee('Foto maken');
    }

    public function test_pdf_shows_drawing_and_excerpt_for_each_snag(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $this->makeSnag($project, $albert, SnagStatus::Open, [
            'number' => 1,
            'x' => 0.25,
            'y' => 0.5,
        ]);
        $drawing = $project->plattegrond();

        $this->actingAs($user)
            ->get(route('projects.snags.export', ['project' => $project, 'drawing' => 1, 'photos' => 0]))
            ->assertOk()
            ->assertSee('Tekening pagina 1 met genummerde punten')
            ->assertSee('Deeltekening')
            ->assertSee('data-drawing-url="'.route('projects.documents.show', [$project, $drawing], false).'"', false)
            ->assertSee('class="excerpt"', false)
            ->assertSee('--x: 0.25; --y: 0.5;', false)
            ->assertSee('Tekening laden');
    }

    public function test_pdf_embeds_image_drawing_on_the_map_and_excerpt(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $drawing = $project->plattegrond();
        $file = UploadedFile::fake()->image('plattegrond.jpg', 800, 600);
        $path = $file->storeAs('projects/'.$project->id.'/plattegrond', 'plan.jpg', 'local');
        $drawing->update([
            'original_filename' => 'plattegrond.jpg',
            'file_path' => $path,
            'mime_type' => 'image/jpeg',
        ]);
        $this->makeSnag($project, $albert, SnagStatus::Open, [
            'number' => 1,
            'x' => 0.25,
            'y' => 0.5,
        ]);

        $html = $this->actingAs($user)
            ->get(route('projects.snags.export', ['project' => $project, 'drawing' => 1, 'photos' => 0]))
            ->assertOk()
            ->assertSee('Deeltekening')
            ->assertSee('class="map-drawing"', false)
            ->assertSee('class="excerpt-drawing"', false)
            ->assertSee(route('projects.documents.show', [$project, $drawing], false), false)
            ->assertDontSee('data-drawing-url=', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'class="map-drawing"'));
        $this->assertSame(1, substr_count($html, 'class="excerpt-drawing"'));
    }

    public function test_clicking_markers_opens_the_matching_snag_and_keeps_edits_after_reload(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $document = $project->plattegrond();

        $this->actingAs($user);
        $this->postSnag($project, $document, $albert, 0.21, 0.41, 'Kim in speellokaal');
        $this->postSnag($project, $document, $albert, 0.49, 0.54, 'Deurhal beschadigd');
        $this->post(route('projects.snags.store', $project), [
            'x' => 0.71,
            'y' => 0.34,
            'drawing_page' => 1,
            'document_id' => $document->id,
            'description' => 'Plint spreekruimte',
            'assigned_worker_id' => $albert->id,
            'photo' => UploadedFile::fake()->image('constatering.jpg', 640, 480),
        ], ['Accept' => 'application/json'])->assertCreated();

        $snags = $project->snags()->with('photos')->orderBy('number')->get();
        $this->assertCount(3, $snags);
        $this->assertSame([1, 2, 3], $snags->pluck('number')->all());

        foreach ($snags as $snag) {
            $this->getJson(route('projects.snags.show', [$project, $snag]))
                ->assertOk()
                ->assertJsonPath('snag.id', $snag->id)
                ->assertJsonPath('snag.number', $snag->number)
                ->assertJsonPath('snag.description', $snag->description);
        }

        $second = $snags[1];
        $this->patchJson(route('projects.snags.update', [$project, $second]), [
            'status' => SnagStatus::InProgress->value,
        ])
            ->assertOk()
            ->assertJsonPath('snag.id', $second->id)
            ->assertJsonPath('snag.status', SnagStatus::InProgress->value)
            ->assertJsonPath('snag.tone', 'progress')
            ->assertJsonPath('snag.status_label', 'In behandeling');

        $third = $snags[2];
        $photo = $third->photos->first();
        $this->assertNotNull($photo);
        $this->get(route('projects.snags.photo', [$project, $third, $photo]))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->patchJson(route('projects.snags.update', [$project, $third]), [
            'x' => 0.81,
            'y' => 0.22,
            'drawing_page' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('snag.id', $third->id)
            ->assertJsonPath('snag.description', 'Plint spreekruimte')
            ->assertJsonPath('snag.number', 3);

        $html = $this->get(route('projects.show', $project))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/id="board-data">([^<]*)<\/script>/', $html, $matches));
        $board = json_decode($matches[1], true);
        $this->assertIsArray($board['snags'] ?? null);
        $byNumber = collect($board['snags'])->keyBy('number');

        $this->assertSame($snags[0]->id, $byNumber[1]['id']);
        $this->assertSame('Kim in speellokaal', $byNumber[1]['description']);
        $this->assertSame($second->id, $byNumber[2]['id']);
        $this->assertSame(SnagStatus::InProgress->value, $byNumber[2]['status']);
        $this->assertSame('progress', $byNumber[2]['tone']);
        $this->assertSame($third->id, $byNumber[3]['id']);
        $this->assertEqualsWithDelta(0.81, $byNumber[3]['x'], 0.0001);
        $this->assertEqualsWithDelta(0.22, $byNumber[3]['y'], 0.0001);
        $this->assertNotEmpty($byNumber[3]['thumb']);
        $this->assertNotEmpty($byNumber[3]['photos']);
    }

    public function test_board_has_place_controls(): void
    {
        Storage::fake('local');
        [, $project] = $this->makeProject();
        $planner = User::factory()->create();

        $this->actingAs($planner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('+ Opleverpunt')
            ->assertSee('id="snag-panel"', false)
            ->assertSee('id="snag-popup"', false)
            ->assertSee('Bekijken / bewerken')
            ->assertSee('Status wijzigen')
            ->assertSee('Vakman wijzigen')
            ->assertSee('id="snag-popup-status"', false)
            ->assertSee('id="snag-popup-worker"', false)
            ->assertSee('for="snag-popup-worker"', false)
            ->assertSee('id="snag-popup-camera-btn"', false)
            ->assertSee('Foto bij status')
            ->assertDontSee('id="snag-popup-delete"', false)
            ->assertDontSee('id="snag-dialog"', false);
    }

    public function test_board_shows_delete_for_an_uitvoerder(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->makeProject();
        $user->update(['role' => UserRole::Uitvoerder]);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('id="snag-popup-delete"', false)
            ->assertSee('Verwijderen');
    }

    public function test_uitvoerder_deletes_a_snag_and_its_photos(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $user->update(['role' => UserRole::Uitvoerder]);
        $snag = $this->makeSnag($project, $albert, SnagStatus::Open);
        $path = UploadedFile::fake()->image('naad.jpg', 40, 40)->store('projects/'.$project->id.'/snags', 'local');
        $photo = SnagPhoto::query()->create([
            'snag_item_id' => $snag->id,
            'file_path' => $path,
            'original_filename' => 'naad.jpg',
            'photo_type' => SnagPhotoType::Issue,
        ]);

        $this->actingAs($user)
            ->deleteJson(route('projects.snags.destroy', [$project, $snag]))
            ->assertOk()
            ->assertJsonPath('deleted', $snag->id);

        $this->assertModelMissing($snag);
        $this->assertDatabaseMissing('snag_photos', ['id' => $photo->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_planner_is_forbidden_from_deleting_a_snag(): void
    {
        Storage::fake('local');
        [, $project, $albert] = $this->makeProject();
        $planner = User::factory()->create();
        $snag = $this->makeSnag($project, $albert, SnagStatus::Open);

        $this->actingAs($planner)
            ->deleteJson(route('projects.snags.destroy', [$project, $snag]))
            ->assertForbidden();

        $this->assertModelExists($snag);
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        Storage::fake('local');
        [, $project, $albert] = $this->makeProject();
        $snag = $this->makeSnag($project, $albert, SnagStatus::Open);

        $this->deleteJson(route('projects.snags.destroy', [$project, $snag]))
            ->assertUnauthorized();

        $this->assertModelExists($snag);
    }

    public function test_delete_of_snag_from_another_project_returns_404(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $user->update(['role' => UserRole::Uitvoerder]);
        $other = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $project->customer_id,
            'name' => 'Ander project',
            'status' => 'gepland',
        ]);
        $foreign = $this->makeSnag($other, $albert, SnagStatus::Open);

        $this->actingAs($user)
            ->deleteJson(route('projects.snags.destroy', [$project, $foreign]))
            ->assertNotFound();

        $this->assertModelExists($foreign);
    }

    public function test_snag_cannot_use_a_room_from_another_project(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $other = Project::query()->create([
            'project_number' => '260200092',
            'customer_id' => $project->customer_id,
            'name' => 'Ander project',
            'status' => 'gepland',
        ]);
        $foreignArea = ProjectArea::query()->create([
            'project_id' => $other->id,
            'area_number' => '9.99',
            'name' => 'vreemde ruimte',
            'square_meters' => 10,
            'status' => 'niet_gestart',
        ]);

        $this->actingAs($user)
            ->postJson(route('projects.snags.store', $project), [
                'x' => 0.22,
                'y' => 0.41,
                'drawing_page' => 1,
                'project_area_id' => $foreignArea->id,
                'description' => 'Kim niet recht',
                'assigned_worker_id' => $albert->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_area_id');

        $this->assertDatabaseMissing('snag_items', [
            'project_id' => $project->id,
            'project_area_id' => $foreignArea->id,
        ]);
    }

    public function test_snag_cannot_use_a_drawing_from_another_project(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $other = Project::query()->create([
            'project_number' => '260200093',
            'customer_id' => $project->customer_id,
            'name' => 'Ander project',
            'status' => 'gepland',
        ]);
        $foreignDocument = ProjectDocument::query()->create([
            'project_id' => $other->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'ander.pdf',
            'file_path' => 'projects/'.$other->id.'/plattegrond/ander.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'parse_status' => 'none',
        ]);

        $this->actingAs($user)
            ->postJson(route('projects.snags.store', $project), [
                'x' => 0.22,
                'y' => 0.41,
                'drawing_page' => 1,
                'document_id' => $foreignDocument->id,
                'description' => 'Kim niet recht',
                'assigned_worker_id' => $albert->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_id');
    }

    public function test_board_json_does_not_break_out_of_the_script_tag(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $this->makeSnag($project, $albert, SnagStatus::Open, [
            'description' => '</script><script>alert(1)</script>',
        ]);

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
        $this->assertSame(1, preg_match('/id="board-data">([^<]*)<\/script>/', $html, $matches));
        $board = json_decode($matches[1], true);
        $this->assertIsArray($board['snags'] ?? null);
        $this->assertSame('</script><script>alert(1)</script>', $board['snags'][0]['description']);
    }

    public function test_svg_photo_is_rejected(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $document = $project->plattegrond();
        $svg = UploadedFile::fake()->createWithContent(
            'xss.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->actingAs($user)
            ->post(route('projects.snags.store', $project), [
                'x' => 0.22,
                'y' => 0.41,
                'drawing_page' => 1,
                'document_id' => $document->id,
                'description' => 'Kim niet recht',
                'assigned_worker_id' => $albert->id,
                'photo' => $svg,
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        $this->assertDatabaseCount('snag_photos', 0);
    }

    public function test_reporting_a_closed_snag_does_not_reopen_it(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $snag = $this->makeSnag($project, $albert, SnagStatus::Closed);

        $this->actingAs($user)
            ->postJson(route('projects.snags.report', [$project, $snag]), [
                'note' => 'Toch gereed',
            ])
            ->assertUnprocessable();

        $this->assertSame(SnagStatus::Closed, $snag->fresh()->status);
    }

    public function test_photo_stays_on_its_own_snag_and_is_hidden_from_another(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $document = $project->plattegrond();

        $this->actingAs($user);
        $this->post(route('projects.snags.store', $project), [
            'x' => 0.21,
            'y' => 0.41,
            'drawing_page' => 1,
            'document_id' => $document->id,
            'description' => 'Eerste punt',
            'assigned_worker_id' => $albert->id,
            'photo' => UploadedFile::fake()->image('eerste.jpg', 40, 40),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->post(route('projects.snags.store', $project), [
            'x' => 0.49,
            'y' => 0.54,
            'drawing_page' => 1,
            'document_id' => $document->id,
            'description' => 'Tweede punt',
            'assigned_worker_id' => $albert->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $first = $project->snags()->where('number', 1)->first();
        $second = $project->snags()->where('number', 2)->first();
        $photo = $first->photos()->first();

        $this->assertNotNull($photo);
        $this->assertSame($first->id, $photo->snag_item_id);
        $this->assertSame(0, $second->photos()->count());

        $this->get(route('projects.snags.photo', [$project, $second, $photo]))
            ->assertNotFound();
        $this->get(route('snags.public.photo', [$second->public_token, $photo]))
            ->assertNotFound();
        $this->get(route('projects.snags.photo', [$project, $first, $photo]))
            ->assertOk();
    }

    public function test_public_link_cannot_mark_a_closed_snag_done_again(): void
    {
        Storage::fake('local');
        [$user, $project, $albert] = $this->makeProject();
        $snag = $this->makeSnag($project, $albert, SnagStatus::Closed);

        $this->post(route('snags.public.complete', $snag->public_token), [
            'note' => 'Toch gereed',
        ])->assertForbidden();

        $this->assertSame(SnagStatus::Closed, $snag->fresh()->status);
    }

    /** @return array{0: User, 1: Project, 2: Worker} */
    private function makeProject(): array
    {
        $user = User::factory()->projectleider()->create();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'email' => 'albert@example.test',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Kindcentrum Veldhoeve',
            'address' => 'Schoolstraat 1',
            'postal_code' => '1234 AB',
            'city' => 'Amersfoort',
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $work = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Marmoleum Real',
            'unit' => 'm2',
            'ordered_quantity' => 200,
            'status' => 'gepland',
            'sort_order' => 10,
        ]);
        foreach ([
            '0.21' => 'speellokaal',
            '0.17' => 'hal',
            '0.24' => 'spreekruimte',
        ] as $number => $name) {
            $area = ProjectArea::query()->create([
                'project_id' => $project->id,
                'project_floor_id' => $floor->id,
                'area_number' => $number,
                'name' => $name,
                'square_meters' => 20,
                'status' => 'niet_gestart',
            ]);
            AreaTask::query()->create([
                'project_area_id' => $area->id,
                'work_item_id' => $work->id,
                'ordered_quantity' => 20,
                'unit' => 'm2',
                'status' => 'niet_gestart',
            ]);
        }
        $file = UploadedFile::fake()->create('plattegrond.pdf', 20, 'application/pdf');
        $path = $file->storeAs('projects/'.$project->id.'/plattegrond', 'plan.pdf', 'local');
        ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'plattegrond',
            'original_filename' => 'plattegrond.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'parse_status' => 'none',
            'uploaded_by' => $user->id,
        ]);

        return [$user, $project->fresh(['areas', 'documents']), $worker];
    }

    private function placeRoom(Project $project, ProjectDocument $document, string $number, float $x, float $y): void
    {
        $area = $project->areas()->where('area_number', $number)->firstOrFail();
        AreaDrawingMarker::query()->create([
            'project_area_id' => $area->id,
            'project_document_id' => $document->id,
            'page' => 1,
            'x' => $x,
            'y' => $y,
            'source' => 'manual',
        ]);
    }

    private function postSnag(Project $project, ProjectDocument $document, Worker $worker, float $x, float $y, string $description): void
    {
        $this->post(route('projects.snags.store', $project), [
            'x' => $x,
            'y' => $y,
            'drawing_page' => 1,
            'document_id' => $document->id,
            'description' => $description,
            'assigned_worker_id' => $worker->id,
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    private function makeSnag(Project $project, Worker $worker, SnagStatus $status, array $extra = []): SnagItem
    {
        return SnagItem::query()->create(array_merge([
            'project_id' => $project->id,
            'project_area_id' => $project->areas()->first()?->id,
            'document_id' => $project->plattegrond()?->id,
            'drawing_page' => 1,
            'x' => 0.2,
            'y' => 0.3,
            'number' => $extra['number'] ?? ($project->snags()->max('number') + 1 ?: 12),
            'description' => 'Herstel nodig',
            'assigned_worker_id' => $worker->id,
            'status' => $status,
        ], $extra));
    }
}
