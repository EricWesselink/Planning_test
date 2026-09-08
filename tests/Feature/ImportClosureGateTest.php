<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\Meetstaat\ImportClosureEvaluator;
use App\Services\Meetstaat\RoomImportAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class ImportClosureGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_shows_importcontrole_panel(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'meetstaat' => $this->meetstaatFile(),
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));

        $this->actingAs($user)
            ->get(route('projects.review', $token))
            ->assertOk()
            ->assertSee('IMPORTCONTROLE')
            ->assertSee('Totaal verwacht')
            ->assertSee('Totaal verwerkt')
            ->assertSee('Verschil')
            ->assertSee('Hoeveelheden:')
            ->assertSee('Projectgegevens')
            ->assertSee('Bronbestanden')
            ->assertSee('Ruimtes');
    }

    public function test_ready_review_keeps_detail_sections_collapsed(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [
                $this->simplePdf(
                    'Meetstaat.pdf',
                    "Meetstaat\nBouwlaag: begane grond\nMarmoleum Real, 3120 rosato, Linoleum\n0.07 groepsruimte 50,97 m²\n"
                ),
            ],
            'types' => ['meetstaat'],
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)
            ->get(route('projects.review', $token))
            ->assertOk()
            ->assertSee('IMPORTCONTROLE')
            ->assertSee('Project definitief importeren')
            ->assertSee('data-review-fold="projectgegevens"', false)
            ->assertSee('data-review-fold="bronbestanden"', false)
            ->assertSee('data-review-fold="ruimtes"', false)
            ->assertDontSee('data-review-fold="projectgegevens" open data-review-open="1"', false)
            ->assertDontSee('data-review-fold="bronbestanden" open data-review-open="1"', false)
            ->assertDontSee('data-review-fold="ruimtes" open data-review-open="1"', false)
            ->assertDontSee('data-review-fold="herkenning-debug" open data-review-open="1"', false);
    }

    public function test_review_opens_rooms_fold_when_a_room_needs_control(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'meetstaat' => $this->meetstaatFile(),
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));

        $payload = Cache::get('meetstaat.'.$token);
        $this->assertNotNull($payload);
        $payload['preview']['areas'][0]['confidence'] = 'controleren';
        $payload['preview']['areas'][0]['needs_review'] = true;
        $payload['preview']['areas'][0]['review_reason_label'] = 'Handmatig open gezet voor fold-test';
        $payload['preview'] = (new ImportClosureEvaluator)->attach($payload['preview']);
        $this->assertFalse($payload['preview']['import_closure']['ready'] ?? true);
        Cache::put('meetstaat.'.$token, $payload, now()->addHour());

        $this->actingAs($user)
            ->get(route('projects.review', $token))
            ->assertOk()
            ->assertSee('data-review-fold="ruimtes" open data-review-open="1"', false)
            ->assertDontSee('data-review-fold="herkenning-debug" open data-review-open="1"', false)
            ->assertDontSee('data-review-fold="projectgegevens" open data-review-open="1"', false);
    }

    public function test_incomplete_import_is_blocked_until_closure_is_ready(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'meetstaat' => $this->meetstaatFile(),
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));

        $payload = Cache::get('meetstaat.'.$token);
        $this->assertNotNull($payload);
        $payload['preview']['areas'][0]['confidence'] = 'controleren';
        $payload['preview']['areas'][0]['needs_review'] = true;
        $payload['preview']['areas'][0]['review_reason_label'] = 'Handmatig open gezet voor gate-test';
        $payload['preview'] = (new ImportClosureEvaluator)->attach($payload['preview']);
        $this->assertFalse($payload['preview']['import_closure']['ready'] ?? true);
        Cache::put('meetstaat.'.$token, $payload, now()->addHour());

        $this->actingAs($user)
            ->from(route('projects.review', $token))
            ->post(route('projects.import', $token), [
                'customer_name' => 'Nicon vloeren',
                'project_name' => 'Nog niet klaar',
                'project_number' => '260299001',
            ])
            ->assertRedirect(route('projects.review', $token))
            ->assertSessionHasErrors('import_closure');

        $this->assertSame(0, Project::query()->count());
    }

    public function test_ready_closure_allows_definitive_import(): void
    {
        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('projects.preview'), [
            'files' => [
                $this->simplePdf(
                    'Meetstaat.pdf',
                    "Meetstaat\nBouwlaag: begane grond\nMarmoleum Real, 3120 rosato, Linoleum\n0.07 groepsruimte 50,97 m²\n"
                ),
            ],
            'types' => ['meetstaat'],
        ]);
        $token = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));
        $this->makeCachedPreviewReady($token);

        $this->actingAs($user)->post(route('projects.import', $token), [
            'customer_name' => 'Nicon vloeren',
            'project_name' => 'Sluitend project',
            'project_number' => '260299002',
        ])->assertRedirect();

        $project = Project::query()->where('project_number', '260299002')->first();
        $this->assertNotNull($project);
        $this->assertSame(1, $project->areas()->count());
    }

    private function makeCachedPreviewReady(string $token): void
    {
        $payload = Cache::get('meetstaat.'.$token);
        $this->assertNotNull($payload);
        $preview = $payload['preview'];
        $preview['sources'] = array_merge($preview['sources'] ?? [], [
            'materialenstaat' => false,
            'kleur' => false,
        ]);
        $preview['closure_baselines'] = [
            'meetstaat_areas' => [],
            'material_works' => [],
        ];
        $preview['uncertain'] = [];
        $preview['duplicates'] = [];
        $preview['legend'] = [];
        $preview['material_check'] = [];
        foreach ($preview['areas'] as &$area) {
            $area['confidence'] = 'hoog';
            $area['needs_review'] = false;
            $area['fill_color'] = null;
            $area['legend_material'] = null;
            if (mb_strtolower(trim((string) ($area['floor'] ?? ''))) === 'onbekend' || trim((string) ($area['floor'] ?? '')) === '') {
                $area['floor'] = 'begane grond';
            }
        }
        unset($area);
        $preview = (new RoomImportAssembler)->refreshClosure($preview);
        $taskMeters = (float) ($preview['import_report']['task_meters'] ?? 0);
        $preview['import_report']['meetstaat_task_meters'] = $taskMeters;
        $preview['import_report']['task_meters_expected'] = $taskMeters;
        foreach ($preview['import_report']['materials'] ?? [] as $index => $row) {
            $preview['import_report']['materials'][$index]['status'] = 'ok';
            $preview['import_report']['materials'][$index]['difference'] = 0.0;
            $preview['import_report']['materials'][$index]['expected_task_meters'] = $row['found_task_meters'] ?? 0;
        }
        foreach ($preview['import_report']['floors'] ?? [] as $index => $row) {
            $preview['import_report']['floors'][$index]['task_meters_difference'] = 0.0;
            $preview['import_report']['floors'][$index]['meetstaat_task_meters'] = $row['task_meters'] ?? 0;
        }
        $preview = (new ImportClosureEvaluator)->attach($preview);
        $this->assertTrue($preview['import_closure']['ready'] ?? false, 'Testpreview moet sluitend gemaakt kunnen worden.');
        $payload['preview'] = $preview;
        Cache::put('meetstaat.'.$token, $payload, now()->addHour());
    }

    private function meetstaatFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/fixtures/nicon-meetbon-laakse-tuinen.pdf'),
            'Meetbon_Laakse_Tuinen.pdf',
            'application/pdf',
            null,
            true
        );
    }

    private function simplePdf(string $name, string $text): UploadedFile
    {
        return new UploadedFile(SimplePdf::path($text), $name, 'application/pdf', null, true);
    }
}
