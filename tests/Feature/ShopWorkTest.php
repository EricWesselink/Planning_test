<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Enums\UserRole;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\WorkActivityCategory;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Services\PlanningBoardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShopWorkTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_new_winkelwerk(): void
    {
        $this->get(route('projects.winkel.create'))->assertRedirect(route('login'));
        $this->post(route('projects.winkel.store'), [])->assertRedirect(route('login'));
        $this->get(route('projects.winkel.werkbon', 1))->assertRedirect(route('login'));
        $this->get(route('projects.winkel.werkbon.pdf', 1))->assertRedirect(route('login'));
    }

    public function test_uitvoerder_cannot_open_or_create_winkelwerk(): void
    {
        $user = User::factory()->uitvoerder()->create();

        $this->actingAs($user)->get(route('projects.winkel.create'))->assertForbidden();
        $this->actingAs($user)->post(route('projects.winkel.store'), $this->payload())->assertForbidden();
    }

    public function test_create_form_shows_catalog_checkboxes_and_hides_disabled_items(): void
    {
        $user = User::factory()->create();
        $horren = $this->addActivity('Horren', 'overig');
        $this->activity('anders')->update(['is_active' => false]);

        $pvc = $this->activity('pvc-banen');
        $html = $this->actingAs($user)
            ->get(route('projects.winkel.create'))
            ->assertOk()
            ->assertSee('Werkzaamheden')
            ->assertSee('>PVC banen</span>', false)
            ->assertSee('>PVC stroken</span>', false)
            ->assertSee('>Primen</span>', false)
            ->assertSee('>Egaliseren</span>', false)
            ->assertSee('Screens')
            ->assertSee('Gordijnen')
            ->assertSee('Inmeten')
            ->assertSee('Werkopname')
            ->assertSee('Montage')
            ->assertSee('Horren')
            ->assertDontSee('>Anders</span>', false)
            ->assertSee('Aantal')
            ->assertSee('Uren')
            ->assertSee('Standaard €48/u')
            ->assertSee('Orderbedrag excl. btw')
            ->assertSee('value="48"', false)
            ->assertSee('lg:grid-cols-2', false)
            ->assertSee('lg:grid-cols-4', false)
            ->assertSee('Telefoon')
            ->assertSee('E-mail')
            ->assertSee('Voorkeur vakman')
            ->assertSee('>m²</option>', false)
            ->assertSee('>m¹</option>', false)
            ->assertSee('>stuks</option>', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="activity-quantity-'.$pvc->id.'"[^>]*\sdisabled(?:="disabled")?\s*>/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="activity-details-'.$pvc->id.'"[^>]*\bhidden\b/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="activity-note-row-'.$pvc->id.'"[^>]*\bhidden\b/s',
            $html
        );

        $this->assertTrue($horren->is_active);
    }

    public function test_create_form_asks_egaliseren_for_floor_coverings_but_not_plinten(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('projects.winkel.create'))
            ->assertOk()
            ->assertSee('Egaliseren?')
            ->assertSee('incl. primen')
            ->assertSee('data-shop-leveling-yes', false)
            ->assertSee('data-shop-leveling-no', false)
            ->assertSee('data-shop-prep="primen"', false)
            ->assertSee('data-shop-prep="egaliseren"', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-shop-floor-covering\s+data-shop-floor-product\s+data-activity-name="PVC banen"/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-shop-floor-covering\s+data-shop-floor-product\s+data-activity-name="Plinten"/',
            $html
        );
    }

    public function test_planner_creates_winkelwerk_with_multiple_activities_notes_and_attachments(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $screens = $this->activity('screens');
        $rolluiken = $this->activity('rolluiken');
        $montage = $this->activity('montage');
        $photo = UploadedFile::fake()->image('achterzijde.jpg', 40, 30);
        $drawing = UploadedFile::fake()->image('tekening.png', 40, 30);

        $response = $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'address' => 'Kerkstraat 12',
            'postal_code' => '7551 AA',
            'work_description' => 'Screens en rolluiken plaatsen, elektrisch aansluiten.',
            'work_activity_ids' => [$screens->id, $rolluiken->id, $montage->id],
            'activity_notes' => [
                $screens->id => '4 stuks plaatsen achterzijde woning',
                $rolluiken->id => '2 stuks slaapkamer',
                $montage->id => 'elektrisch aansluiten en afstellen',
            ],
            'attachments' => [$photo, $drawing],
            'start_year' => 2026,
            'start_week' => 37,
            'klaar_year' => 2026,
            'klaar_week' => 37,
        ]);

        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $response->assertRedirect(route('projects.show', $project));

        $this->assertSame('Jansen - Hengelo', $project->name);
        $this->assertSame('Hengelo', $project->city);
        $this->assertSame('Screens en rolluiken plaatsen, elektrisch aansluiten.', $project->work_description);
        $this->assertSame(['Screens', 'Rolluiken', 'Montage'], $project->workActivities()->pluck('name')->all());
        $this->assertSame('4 stuks plaatsen achterzijde woning', $project->workActivities()->where('slug', 'screens')->first()?->pivot?->notes);
        $this->assertSame(3, $project->workItems()->count());
        $this->assertSame('48.00', $project->basis_uurtarief);
        $this->assertSame('48.00', $project->workItems()->first()?->uurtarief);
        $this->assertSame(2, $project->documents()->where('document_type', 'bijlage')->count());

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('WINKEL')
            ->assertSee('Jansen - Hengelo')
            ->assertSee('Zonwering · Screens + Rolluiken + Montage')
            ->assertSee('4 stuks plaatsen achterzijde woning')
            ->assertSee('achterzijde.jpg')
            ->assertSee('Open planning')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/id="activity-quantity-'.$screens->id.'"[^>]*\sdisabled(?:="disabled")?\s*>/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="activity-details-'.$screens->id.'"[^>]*\bhidden\b/s',
            $html
        );
    }

    public function test_planner_saves_customer_phone_and_email_on_winkelwerk(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $screens = $this->activity('screens');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'address' => 'Kerkstraat 12',
            'postal_code' => '7551 AA',
            'contact_phone' => '06 12345678',
            'contact_email' => 'jansen@example.nl',
            'work_activity_ids' => [$screens->id],
        ])->assertRedirect();

        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $this->assertSame('06 12345678', $project->contact_phone);
        $this->assertSame('jansen@example.nl', $project->contact_email);
        $this->assertSame('06 12345678', $project->customer?->phone);
        $this->assertSame('jansen@example.nl', $project->customer?->email);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('name="contact_phone"', false)
            ->assertSee('name="contact_email"', false)
            ->assertSee('06 12345678')
            ->assertSee('jansen@example.nl')
            ->assertSee('href="tel:06 12345678"', false)
            ->assertSee('href="mailto:jansen@example.nl"', false);

        $this->actingAs($user)->patch(route('projects.winkel.update', $project), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'address' => 'Kerkstraat 12',
            'postal_code' => '7551 AA',
            'contact_phone' => '053 1234567',
            'contact_email' => 'info@jansen.nl',
            'work_activity_ids' => [$screens->id],
        ])->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame('053 1234567', $project->contact_phone);
        $this->assertSame('info@jansen.nl', $project->contact_email);
        $this->assertSame('053 1234567', $project->customer?->fresh()?->phone);
        $this->assertSame('info@jansen.nl', $project->customer?->fresh()?->email);
    }

    public function test_rejects_winkelwerk_with_an_invalid_email(): void
    {
        $user = User::factory()->create();
        $screens = $this->activity('screens');

        $this->actingAs($user)
            ->from(route('projects.winkel.create'))
            ->post(route('projects.winkel.store'), [
                'customer_name' => 'Jansen',
                'city' => 'Hengelo',
                'work_activity_ids' => [$screens->id],
                'contact_email' => 'niet-geldig',
            ])
            ->assertRedirect(route('projects.winkel.create'))
            ->assertSessionHasErrors(['contact_email' => 'Vul een geldig e-mailadres in.']);

        $this->assertSame(0, Project::query()->count());
    }

    public function test_rejects_winkelwerk_without_selected_activities(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('projects.winkel.create'))
            ->post(route('projects.winkel.store'), [
                'customer_name' => 'Jansen',
                'city' => 'Hengelo',
            ])
            ->assertRedirect(route('projects.winkel.create'))
            ->assertSessionHasErrors(['work_activity_ids']);

        $this->assertSame(0, Project::query()->count());
    }

    public function test_planner_saves_activity_quantity_in_square_meters_or_pieces(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');
        $screens = $this->activity('screens');

        $response = $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$pvc->id, $screens->id],
            'activity_notes' => [
                $pvc->id => 'woonkamer',
                $screens->id => 'achterzijde woning',
            ],
            'activity_quantities' => [
                $pvc->id => '40,5',
                $screens->id => '4',
            ],
            'activity_units' => [
                $pvc->id => WorkUnit::SquareMeter->value,
                $screens->id => WorkUnit::Pieces->value,
            ],
        ]);

        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $response->assertRedirect(route('projects.show', $project));

        $pvcPivot = $project->workActivities()->where('slug', 'pvc-banen')->first()?->pivot;
        $screensPivot = $project->workActivities()->where('slug', 'screens')->first()?->pivot;
        $this->assertSame(40.5, (float) $pvcPivot?->quantity);
        $this->assertSame(WorkUnit::SquareMeter, $pvcPivot?->unit);
        $this->assertSame(4.0, (float) $screensPivot?->quantity);
        $this->assertSame(WorkUnit::Pieces, $screensPivot?->unit);

        $pvcItem = $project->workItems()->where('name', 'PVC banen')->first();
        $screensItem = $project->workItems()->where('name', 'Screens')->first();
        $this->assertSame(WorkUnit::SquareMeter, $pvcItem?->unit);
        $this->assertSame(40.5, (float) $pvcItem?->ordered_quantity);
        $this->assertSame(WorkUnit::Pieces, $screensItem?->unit);
        $this->assertSame(4.0, (float) $screensItem?->ordered_quantity);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('40,50 m²')
            ->assertSee('4 stuks')
            ->assertSee('woonkamer');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('40,50 m²')
            ->assertSee('4 stuks');
    }

    public function test_planner_saves_plinten_in_linear_meters(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $plinten = $this->activity('plinten');

        $this->actingAs($user)
            ->get(route('projects.winkel.create'))
            ->assertOk()
            ->assertSee('value="'.$plinten->id.'"', false)
            ->assertSee('value="'.WorkUnit::LinearMeter->value.'"', false);

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$plinten->id],
            'activity_quantities' => [$plinten->id => '24,5'],
            'activity_hours' => [$plinten->id => '2'],
        ])->assertRedirect();

        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $item = $project->workItems()->where('name', 'Plinten')->first();
        $pivot = $project->workActivities()->where('slug', 'plinten')->first()?->pivot;
        $this->assertSame(WorkUnit::LinearMeter, $item?->unit);
        $this->assertSame(24.5, (float) $item?->ordered_quantity);
        $this->assertSame(WorkUnit::LinearMeter, $pivot?->unit);
        $this->assertSame(24.5, (float) $pivot?->quantity);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('24,50 m¹')
            ->assertSee('€96')
            ->assertSee('€3,92/m¹');
    }

    public function test_rejects_winkelwerk_when_activity_unit_is_not_square_meters_or_pieces(): void
    {
        $user = User::factory()->create();
        $screens = $this->activity('screens');

        $this->actingAs($user)
            ->from(route('projects.winkel.create'))
            ->post(route('projects.winkel.store'), [
                'customer_name' => 'Jansen',
                'city' => 'Hengelo',
                'work_activity_ids' => [$screens->id],
                'activity_quantities' => [$screens->id => '4'],
                'activity_units' => [$screens->id => WorkUnit::Hours->value],
            ])
            ->assertRedirect(route('projects.winkel.create'))
            ->assertSessionHasErrors(['activity_units.'.$screens->id => 'Kies m², m¹ of stuks.']);

        $this->assertSame(0, Project::query()->count());
    }

    public function test_planner_saves_begrote_uren_per_activity_for_planning(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');
        $screens = $this->activity('screens');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$pvc->id, $screens->id],
            'activity_quantities' => [
                $pvc->id => '40,5',
                $screens->id => '4',
            ],
            'activity_units' => [
                $pvc->id => WorkUnit::SquareMeter->value,
                $screens->id => WorkUnit::Pieces->value,
            ],
            'activity_hours' => [
                $pvc->id => '8,5',
                $screens->id => '4',
            ],
            'start_year' => 2026,
            'start_week' => 37,
            'klaar_year' => 2026,
            'klaar_week' => 37,
        ])->assertRedirect();

        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);

        $pvcItem = $project->workItems()->where('name', 'PVC banen')->first();
        $screensItem = $project->workItems()->where('name', 'Screens')->first();
        $this->assertSame(8.5, (float) $pvcItem?->begrote_uren);
        $this->assertSame(4.0, (float) $screensItem?->begrote_uren);
        $this->assertSame('48.00', $project->basis_uurtarief);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('name="activity_hours['.$pvc->id.']"', false)
            ->assertSee('value="48"', false)
            ->assertSee('· 8,5u')
            ->assertSee('· 4u')
            ->assertSee('€408')
            ->assertSee('€10,07/m²')
            ->assertSee('€192')
            ->assertDontSee('meer ingepland dan begroot');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('8,5u')
            ->assertSee('4u');
    }

    public function test_winkelwerk_shows_hours_against_budget_once_when_planning_overruns(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Eric Wesselink',
            'city' => 'Keijenborg',
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '100'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
            'activity_hours' => [$pvc->id => '8'],
            'basis_uurtarief' => '48',
            'start_year' => 2026,
            'start_week' => 37,
            'klaar_year' => 2026,
            'klaar_week' => 37,
        ])->assertRedirect();

        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $item = $project->workItems()->where('name', 'PVC banen')->first();
        $this->assertNotNull($item);

        $assignment = new WorkerAssignment([
            'worker_id' => $this->makeShopWorker('Kees Jansen')->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'people_count' => 1,
        ]);
        $assignment->applySchedule(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-11'),
            '08:00:00',
            '16:00:00',
        );
        $assignment->save();

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('sm:grid-cols-4', false)
            ->assertSee('>Order</div>', false)
            ->assertSee('>Begrote kosten</div>', false)
            ->assertSee('>Werkelijke kosten</div>', false)
            ->assertSee('>Resultaat</div>', false)
            ->assertSee('Order —')
            ->assertSee('Begroot 8u · Ingepland ', false)
            ->assertSee('Gemaakt 0u')
            ->assertSee('Prognose 32u / 400% boven urenbudget')
            ->assertSee('Tarief €48/u')
            ->assertSee('Arbeid €0')
            ->assertDontSee('Arbeid €1.920')
            ->assertSee('Gereed 0 m²')
            ->assertSee('Begroot €3,84/m²')
            ->assertSee('Werkelijk €/m² —')
            ->assertSee('font-medium text-nicon-warn', false)
            ->assertSee('font-medium text-nicon-danger', false)
            ->assertSee('40 / 8 uur')
            ->assertDontSee('⚠ Project:')
            ->assertDontSee('Budget over')
            ->assertDontSee('Planningverschil')
            ->assertDontSee('Begroot 8u | Ingepland 40u')
            ->assertDontSee('⚠ 32 uur meer ingepland dan begroot')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Prognose 32u / 400% boven urenbudget'));
    }

    public function test_planner_saves_and_updates_the_winkelwerk_order_amount(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Eric Wesselink',
            'city' => 'Keijenborg',
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '100'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
            'activity_hours' => [$pvc->id => '8'],
            'basis_uurtarief' => '48',
            'order_amount' => '10.000,00',
        ])->assertRedirect();

        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $this->assertSame('10000.00', $project->order_amount);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Order €10.000')
            ->assertSee('Kosten €0')
            ->assertSee('Resultaat +€10.000 (100%)')
            ->assertSee('name="order_amount"', false)
            ->assertSee('value="10000"', false);

        $this->actingAs($user)->patch(route('projects.winkel.update', $project), [
            'customer_name' => 'Eric Wesselink',
            'city' => 'Keijenborg',
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '100'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
            'activity_hours' => [$pvc->id => '8'],
            'basis_uurtarief' => '48',
            'order_amount' => '8500',
        ])->assertRedirect(route('projects.show', $project));

        $this->assertSame('8500.00', $project->fresh()->order_amount);
    }

    public function test_rejects_a_negative_winkelwerk_order_amount(): void
    {
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');

        $this->actingAs($user)
            ->from(route('projects.winkel.create'))
            ->post(route('projects.winkel.store'), [
                'customer_name' => 'Jansen',
                'city' => 'Hengelo',
                'work_activity_ids' => [$pvc->id],
                'order_amount' => '-1',
            ])
            ->assertRedirect(route('projects.winkel.create'))
            ->assertSessionHasErrors(['order_amount' => 'Het orderbedrag kan niet lager zijn dan 0.']);

        $this->assertSame(0, Project::query()->count());
    }

    public function test_planner_saves_a_custom_winkelwerk_hourly_rate(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$pvc->id],
            'activity_hours' => [$pvc->id => '2'],
            'basis_uurtarief' => '52,50',
        ])->assertRedirect();

        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $this->assertSame('52.50', $project->basis_uurtarief);
        $this->assertSame('52.50', $project->workItems()->first()?->uurtarief);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('value="52.50"', false)
            ->assertSee('€105');
    }

    public function test_rejects_winkelwerk_when_activity_hours_are_negative(): void
    {
        $user = User::factory()->create();
        $screens = $this->activity('screens');

        $this->actingAs($user)
            ->from(route('projects.winkel.create'))
            ->post(route('projects.winkel.store'), [
                'customer_name' => 'Jansen',
                'city' => 'Hengelo',
                'work_activity_ids' => [$screens->id],
                'activity_hours' => [$screens->id => '-1'],
            ])
            ->assertRedirect(route('projects.winkel.create'))
            ->assertSessionHasErrors(['activity_hours.'.$screens->id => 'Begrote uren kunnen niet lager zijn dan 0.']);

        $this->assertSame(0, Project::query()->count());
    }

    public function test_planning_shows_winkel_badge_and_allows_assigning_a_person(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'specialty' => 'PVC',
            'active' => true,
        ]);
        $pvc = $this->activity('pvc-banen');
        $egaliseren = $this->activity('egaliseren');
        $plinten = $this->activity('plinten');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'De Vries',
            'city' => 'Enschede',
            'work_activity_ids' => [$pvc->id, $egaliseren->id, $plinten->id],
            'start_year' => 2026,
            'start_week' => 37,
            'klaar_year' => 2026,
            'klaar_week' => 37,
        ])->assertRedirect();

        $project = Project::query()->first();
        $this->assertNotNull($project);
        $screensItem = $project->workItems()->where('name', 'PVC banen')->first();
        $this->assertNotNull($screensItem);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Kloppenburg Interieur')
            ->assertSee('De Vries - Enschede')
            ->assertSee('Vloeren · PVC banen + Egaliseren + Plinten')
            ->assertSee('PVC banen')
            ->assertSee('Egaliseren')
            ->assertSee('Plinten');

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $screensItem->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-08',
                'people_count' => 1,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $screensItem->id,
        ]);
        $this->assertSame(1, WorkerAssignment::query()->count());
    }

    public function test_planning_shows_primen_and_egaliseren_with_the_floor_square_meters(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');
        $primen = $this->activity('primen');
        $egaliseren = $this->activity('egaliseren');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Eding',
            'city' => 'Reeve',
            'work_activity_ids' => [$pvc->id, $primen->id, $egaliseren->id],
            'activity_quantities' => [
                $pvc->id => '141,08',
                $primen->id => '141,08',
                $egaliseren->id => '141,08',
            ],
            'activity_units' => [
                $pvc->id => WorkUnit::SquareMeter->value,
                $primen->id => WorkUnit::SquareMeter->value,
                $egaliseren->id => WorkUnit::SquareMeter->value,
            ],
            'start_year' => 2026,
            'start_week' => 37,
            'klaar_year' => 2026,
            'klaar_week' => 37,
        ])->assertRedirect();

        $project = Project::query()->first();
        $this->assertNotNull($project);
        $this->assertSame(141.08, (float) $project->workItems()->where('name', 'Primen')->value('ordered_quantity'));
        $this->assertSame(141.08, (float) $project->workItems()->where('name', 'Egaliseren')->value('ordered_quantity'));

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Vloeren · PVC banen + Primen + Egaliseren')
            ->assertSee('Primen & Egaliseren')
            ->assertSeeInOrder(['Primen & Egaliseren', 'PVC banen'])
            ->assertSee('141,08 m²');

        $request = Request::create('/planning', 'GET', [
            'week' => '2026-09-07',
            'project_id' => $project->id,
        ]);
        $request->setUserResolver(fn () => $user);
        $row = collect(app(PlanningBoardService::class)->build($request)['rows'])
            ->firstWhere('id', $project->id);

        $this->assertNotNull($row);
        $this->assertSame(['Primen & Egaliseren', 'PVC banen'], collect($row['children'])->pluck('title')->all());
        $this->assertSame(141.08, $row['children'][0]['ordered']);
        $this->assertSame(141.08, $row['children'][1]['ordered']);
    }

    public function test_planning_shows_inmeten_without_square_meters_and_rejects_zzp(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $eric = Worker::query()->create([
            'name' => 'Eric Wesselink',
            'employment_type' => 'eigen',
            'specialty' => 'Inmeten, Werkopname',
            'active' => true,
        ]);
        $nick = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'zzp',
            'specialty' => 'Inmeten',
            'active' => true,
        ]);
        $inmeten = $this->activity('inmeten');
        $pvc = $this->activity('pvc-banen');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Kloppenburg',
            'city' => 'Enschede',
            'work_activity_ids' => [$inmeten->id, $pvc->id],
            'activity_quantities' => [
                $inmeten->id => '50',
                $pvc->id => '50',
            ],
            'activity_units' => [
                $inmeten->id => WorkUnit::SquareMeter->value,
                $pvc->id => WorkUnit::SquareMeter->value,
            ],
            'start_year' => 2026,
            'start_week' => 37,
            'klaar_year' => 2026,
            'klaar_week' => 37,
        ])->assertRedirect();

        $project = Project::query()->first();
        $this->assertNotNull($project);
        $inmetenItem = $project->workItems()->where('name', 'Inmeten')->first();
        $this->assertNotNull($inmetenItem);

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $nick->id,
                'project_id' => $project->id,
                'work_item_id' => $inmetenItem->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-08',
                'people_count' => 1,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nick Seine is geen eigen medewerker voor Inmeten.');

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $eric->id,
                'project_id' => $project->id,
                'work_item_id' => $inmetenItem->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-08',
                'people_count' => 1,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertOk();

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Inmeten')
            ->assertSee('09:00 – Inmeten – Kloppenburg - Enschede')
            ->getContent();

        $request = Request::create('/planning', 'GET', [
            'week' => '2026-09-07',
            'project_id' => $project->id,
        ]);
        $request->setUserResolver(fn () => $user);
        $row = collect(app(PlanningBoardService::class)->build($request)['rows'])
            ->firstWhere('id', $project->id);

        $this->assertNotNull($row);
        $inmetenRow = collect($row['children'])->firstWhere('title', 'Inmeten');
        $this->assertNotNull($inmetenRow);
        $this->assertNull($inmetenRow['ordered']);
        $this->assertSame('', $inmetenRow['unit']);
        $this->assertStringContainsString('09:00 – Inmeten – Kloppenburg - Enschede', $html);
    }

    public function test_winkel_assignment_to_pvc_renders_on_the_pvc_row(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'specialty' => 'PVC',
            'active' => true,
        ]);
        $pvc = $this->activity('pvc-banen');
        $marmoleum = $this->activity('marmoleum');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Eric Wesselink',
            'city' => 'Keijenborg',
            'work_activity_ids' => [$pvc->id, $marmoleum->id],
            'activity_quantities' => [$pvc->id => '1', $marmoleum->id => '1'],
            'activity_units' => [
                $pvc->id => WorkUnit::Pieces->value,
                $marmoleum->id => WorkUnit::Pieces->value,
            ],
            'start_year' => 2026,
            'start_week' => 38,
            'klaar_year' => 2026,
            'klaar_week' => 38,
        ])->assertRedirect();

        $project = Project::query()->first();
        $this->assertNotNull($project);
        $pvcItem = $project->workItems()->where('name', 'PVC banen')->first();
        $marmItem = $project->workItems()->where('name', 'Marmoleum')->first();
        $this->assertNotNull($pvcItem);
        $this->assertNotNull($marmItem);

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $pvcItem->id,
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-14',
                'people_count' => 1,
            ])
            ->assertOk();

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-14', 'project_id' => $project->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-work-item-id="'.$pvcItem->id.'"[^>]*>[\s\S]*?class="person-bar[\s\S]*?top: 4px/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-work-item-id="'.$marmItem->id.'"[^>]*>[\s\S]*?class="person-bar/',
            $html
        );
    }

    public function test_planner_updates_winkelwerk_activities_and_description(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $screens = $this->activity('screens');
        $rolluiken = $this->activity('rolluiken');
        $gordijnen = $this->activity('gordijnen');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$screens->id, $rolluiken->id],
            'activity_notes' => [$screens->id => '4 stuks achterzijde'],
            'activity_quantities' => [$screens->id => '4'],
            'activity_units' => [$screens->id => WorkUnit::Pieces->value],
        ])->assertRedirect();

        $project = Project::query()->first();
        $this->assertNotNull($project);

        $this->actingAs($user)->patch(route('projects.winkel.update', $project), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_description' => 'Raambekleding nagekomen.',
            'work_activity_ids' => [$screens->id, $gordijnen->id],
            'activity_notes' => [
                $screens->id => '3 stuks achterzijde',
                $gordijnen->id => 'woonkamer',
            ],
            'activity_quantities' => [
                $screens->id => '3',
                $gordijnen->id => '8',
            ],
            'activity_units' => [
                $screens->id => WorkUnit::Pieces->value,
                $gordijnen->id => WorkUnit::Pieces->value,
            ],
            'activity_hours' => [
                $screens->id => '6',
                $gordijnen->id => '2,5',
            ],
        ])->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame('Raambekleding nagekomen.', $project->work_description);
        $this->assertSame(['Gordijnen', 'Screens'], $project->workActivities()->pluck('name')->all());
        $this->assertSame('3 stuks achterzijde', $project->workActivities()->where('slug', 'screens')->first()?->pivot?->notes);
        $this->assertSame(3.0, (float) $project->workActivities()->where('slug', 'screens')->first()?->pivot?->quantity);
        $this->assertSame(8.0, (float) $project->workActivities()->where('slug', 'gordijnen')->first()?->pivot?->quantity);
        $this->assertSame(['Gordijnen', 'Screens'], $project->workItems()->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(3.0, (float) $project->workItems()->where('name', 'Screens')->first()?->ordered_quantity);
        $this->assertSame(6.0, (float) $project->workItems()->where('name', 'Screens')->first()?->begrote_uren);
        $this->assertSame(2.5, (float) $project->workItems()->where('name', 'Gordijnen')->first()?->begrote_uren);
        $this->assertSame('Raambekleding + Zonwering · Gordijnen + Screens', $project->fresh(['workActivities.category'])->shopWorkLine());
    }

    public function test_construction_project_does_not_show_winkel_badge_on_planning(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Laakse Tuinen',
            'customer_name' => 'Gemeente Amersfoort',
            'city' => 'Amersfoort',
        ])->assertRedirect();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertDontSee('plan-winkel-badge', false)
            ->assertSee('Laakse Tuinen');
    }

    public function test_create_form_lists_a_free_vakman_as_voorkeur(): void
    {
        $user = User::factory()->create();
        $kees = $this->makeShopWorker('Kees Jansen');

        $this->actingAs($user)
            ->get(route('projects.winkel.create'))
            ->assertOk()
            ->assertSee('Voorkeur vakman')
            ->assertSee('Kees Jansen')
            ->assertSee('value="'.$kees->id.'"', false);
    }

    public function test_winkel_assigns_a_free_voorkeur_vakman_to_the_planned_weeks(): void
    {
        $user = User::factory()->create();
        $kees = $this->makeShopWorker('Kees Jansen');
        $screens = $this->activity('screens');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$screens->id],
            'worker_id' => $kees->id,
            'start_year' => 2026,
            'start_week' => 40,
            'klaar_year' => 2026,
            'klaar_week' => 40,
        ])->assertRedirect();

        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $assignment = $project->assignments()->first();
        $this->assertNotNull($assignment);
        $this->assertSame($kees->id, (int) $assignment->worker_id);
        $this->assertSame($project->workItems()->first()?->id, (int) $assignment->work_item_id);
        $this->assertSame('2026-09-28', $assignment->start_date?->toDateString());
        $this->assertSame('2026-10-03', $assignment->end_date?->toDateString());
    }

    public function test_winkel_rejects_a_busy_voorkeur_vakman(): void
    {
        $user = User::factory()->create();
        $kees = $this->makeShopWorker('Kees Jansen');
        $this->bookWorker($kees, '2026-09-28', '2026-10-02');
        $screens = $this->activity('screens');

        $this->actingAs($user)
            ->from(route('projects.winkel.create'))
            ->post(route('projects.winkel.store'), [
                'customer_name' => 'Jansen',
                'city' => 'Hengelo',
                'work_activity_ids' => [$screens->id],
                'worker_id' => $kees->id,
                'start_year' => 2026,
                'start_week' => 40,
                'klaar_year' => 2026,
                'klaar_week' => 40,
            ])
            ->assertRedirect(route('projects.winkel.create'))
            ->assertSessionHasErrors(['worker_id' => 'Kees Jansen is niet vrij (Bezet 08:00-16:00).']);

        $this->assertSame(0, Project::query()->where('kind', ProjectKind::Winkel)->count());
    }

    public function test_winkel_requires_weeks_before_selecting_a_voorkeur_vakman(): void
    {
        $user = User::factory()->create();
        $kees = $this->makeShopWorker('Kees Jansen');
        $screens = $this->activity('screens');

        $this->actingAs($user)
            ->from(route('projects.winkel.create'))
            ->post(route('projects.winkel.store'), [
                'customer_name' => 'Jansen',
                'city' => 'Hengelo',
                'work_activity_ids' => [$screens->id],
                'worker_id' => $kees->id,
            ])
            ->assertRedirect(route('projects.winkel.create'))
            ->assertSessionHasErrors(['worker_id' => 'Kies eerst start- en klaarweek om een vakman te kiezen.']);
    }

    public function test_planner_can_change_the_winkel_voorkeur_vakman_on_the_planning(): void
    {
        $user = User::factory()->create();
        $kees = $this->makeShopWorker('Kees Jansen');
        $piet = $this->makeShopWorker('Piet de Vries');
        $screens = $this->activity('screens');

        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$screens->id],
            'worker_id' => $kees->id,
            'start_year' => 2026,
            'start_week' => 40,
            'klaar_year' => 2026,
            'klaar_week' => 40,
        ])->assertRedirect();

        $assignment = WorkerAssignment::query()->first();
        $this->assertNotNull($assignment);
        $this->assertSame($kees->id, (int) $assignment->worker_id);

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $piet->id,
                'start_date' => '2026-09-28',
                'end_date' => '2026-10-02',
            ])
            ->assertOk();

        $this->assertSame($piet->id, (int) $assignment->fresh()?->worker_id);
    }

    public function test_available_workers_endpoint_marks_a_busy_vakman(): void
    {
        $user = User::factory()->create();
        $kees = $this->makeShopWorker('Kees Jansen');
        $this->bookWorker($kees, '2026-09-28', '2026-10-02');

        $this->actingAs($user)
            ->getJson(route('projects.winkel.available-workers', [
                'start_date' => '2026-09-28',
                'end_date' => '2026-10-03',
            ]))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $kees->id,
                'name' => 'Kees Jansen',
                'selectable' => false,
            ]);
    }

    public function test_uitvoerder_cannot_list_winkel_available_workers(): void
    {
        $user = User::factory()->uitvoerder()->create();

        $this->actingAs($user)
            ->getJson(route('projects.winkel.available-workers'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_winkel_available_workers(): void
    {
        $this->getJson(route('projects.winkel.available-workers'))->assertUnauthorized();
    }

    #[DataProvider('rolesThatMayCreate')]
    public function test_roles_that_may_create_winkelwerk(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('projects.winkel.create'))->assertOk();
    }

    public function test_winkelwerk_page_offers_werkbon_view_and_pdf(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');
        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Eric Wesselink',
            'city' => 'Keijenborg',
            'work_activity_ids' => [$pvc->id],
            'activity_quantities' => [$pvc->id => '12.5'],
            'activity_units' => [$pvc->id => WorkUnit::SquareMeter->value],
        ])->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('>Werkbon</a>', false)
            ->assertSee('Download PDF')
            ->assertSee(route('projects.winkel.werkbon', $project, false), false)
            ->assertSee(route('projects.winkel.werkbon.pdf', $project, false), false);
    }

    public function test_winkel_werkbon_shows_shop_work_and_print_download(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $meterWorker = Worker::query()->create([
            'name' => 'Inmeter Jansen',
            'employment_type' => 'eigen',
            'specialty' => 'Inmeten',
            'active' => true,
        ]);
        $meter = User::factory()->create([
            'name' => 'Inmeter Jansen',
            'worker_id' => $meterWorker->id,
        ]);
        $pvc = $this->activity('pvc-banen');
        $tapijt = $this->activity('tapijt');
        $plinten = $this->activity('plinten');
        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Eric Wesselink',
            'city' => 'Keijenborg',
            'address' => 'Wolsinkweg 4',
            'postal_code' => '7256 KA',
            'contact_phone' => '0612345678',
            'contact_email' => 'wesselinkeric@hotmail.com',
            'work_description' => 'PVC en tapijt in de woning',
            'work_activity_ids' => [$pvc->id, $tapijt->id, $plinten->id],
            'activity_quantities' => [
                $pvc->id => '18.77',
                $tapijt->id => '6.25',
                $plinten->id => '12.50',
            ],
            'activity_units' => [
                $pvc->id => WorkUnit::SquareMeter->value,
                $tapijt->id => WorkUnit::SquareMeter->value,
                $plinten->id => WorkUnit::LinearMeter->value,
            ],
            'activity_hours' => [
                $pvc->id => '16',
                $tapijt->id => '16',
                $plinten->id => '3',
            ],
            'activity_notes' => [
                $pvc->id => 'rechterplank donker eiken',
            ],
            'measurement' => [
                'meter_user_id' => $meter->id,
                'ordered_at' => '2026-09-10',
                'installation_at' => '2026-09-20',
                'rows' => [
                    [
                        'room' => 'dichte trap',
                        'product' => 'Tapijt',
                        'brand' => 'ambiant',
                        'type' => 'nashville',
                        'color_number' => 'antraciet 6760.020543',
                        'quantity' => '3.00',
                        'unit' => WorkUnit::SquareMeter->value,
                        'underlay' => 'rubber',
                        'skirting' => '',
                        'steps' => '',
                        'profile' => '',
                        'available_on_site' => '1',
                        'available_location' => 'nicon',
                    ],
                ],
            ],
        ])->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);

        $this->actingAs($user)
            ->get(route('projects.winkel.werkbon', $project))
            ->assertOk()
            ->assertSee('WERKBON')
            ->assertSee('Kloppenburg Interieur')
            ->assertSee('Eric Wesselink - Keijenborg')
            ->assertSee('Wolsinkweg 4')
            ->assertSee('Tel. 0612345678')
            ->assertDontSee('wesselinkeric@hotmail.com')
            ->assertSee('PVC banen — rechterplank donker eiken')
            ->assertSee('18,77 m²')
            ->assertSee('Tapijt')
            ->assertSee('6,25 m²')
            ->assertSee('Plinten')
            ->assertSee('12,50 m¹')
            ->assertDontSee(' · 16u')
            ->assertDontSee(' · 3u')
            ->assertSee('PVC en tapijt in de woning')
            ->assertSee('dichte trap')
            ->assertSee('nashville')
            ->assertSee('antraciet 6760.020543')
            ->assertSee('rubber')
            ->assertSee('Vakmannen')
            ->assertSee('Nog niet ingepland')
            ->assertDontSee('Opdrachtnemer')
            ->assertSee('Afdrukken')
            ->assertSee('Download PDF')
            ->assertSee(route('projects.winkel.werkbon.pdf', $project, false), false)
            ->assertDontSee('€');
    }

    public function test_winkel_werkbon_lists_vakmannen_and_when_they_go(): void
    {
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');
        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Dussen',
            'city' => 'IJsselmuiden',
            'work_activity_ids' => [$pvc->id],
        ])->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);
        $item = $project->workItems()->first();
        $this->assertNotNull($item);
        $arek = Worker::query()->create([
            'name' => 'Team 3 Arek',
            'employment_type' => 'eigen',
            'specialty' => 'PVC',
            'people_count' => 1,
            'crew_members' => [['name' => 'Arek Kowalski', 'phone' => '']],
            'active' => true,
        ]);
        $lukasz = Worker::query()->create([
            'name' => 'Team 4 Lukasz',
            'employment_type' => 'eigen',
            'specialty' => 'PVC',
            'people_count' => 1,
            'crew_members' => [['name' => 'Lukasz Nowak', 'phone' => '']],
            'active' => true,
        ]);
        $arekPerson = $arek->crewPeople()->first();
        $lukaszPerson = $lukasz->crewPeople()->first();
        $this->assertNotNull($arekPerson);
        $this->assertNotNull($lukaszPerson);
        $arekAssignment = WorkerAssignment::query()->create([
            'worker_id' => $arek->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-21',
            'end_date' => '2026-09-23',
            'hours_per_day' => 8,
        ]);
        $arekAssignment->syncPresentCrew([$arekPerson->id]);
        $arekAssignment->applyRoles($arekPerson->id, $arekPerson->id);
        $lukaszAssignment = WorkerAssignment::query()->create([
            'worker_id' => $lukasz->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-21',
            'end_date' => '2026-09-23',
            'hours_per_day' => 8,
        ]);
        $lukaszAssignment->syncPresentCrew([$lukaszPerson->id]);
        $lukaszAssignment->applyRoles($lukaszPerson->id, $lukaszPerson->id);

        $this->actingAs($user)
            ->get(route('projects.winkel.werkbon', $project))
            ->assertOk()
            ->assertSee('Vakmannen')
            ->assertSee('Arek Kowalski')
            ->assertSee('Lukasz Nowak')
            ->assertSee('Voorman')
            ->assertSee('Werkbon bij')
            ->assertDontSee('Team 3 Arek')
            ->assertDontSee('Team 4 Lukasz')
            ->assertSee('Wanneer')
            ->assertSee('21-09 t/m 23-09-2026')
            ->assertDontSee('Opdrachtnemer');
    }

    public function test_winkel_werkbon_pdf_downloads(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pvc = $this->activity('pvc-banen');
        $this->actingAs($user)->post(route('projects.winkel.store'), [
            'customer_name' => 'Eric Wesselink',
            'city' => 'Keijenborg',
            'work_activity_ids' => [$pvc->id],
        ])->assertRedirect();
        $project = Project::query()->where('kind', ProjectKind::Winkel)->first();
        $this->assertNotNull($project);

        $response = $this->actingAs($user)->get(route('projects.winkel.werkbon.pdf', $project));
        $response->assertOk();
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));
        $response->assertDownload();
    }

    public function test_construction_project_has_no_winkel_werkbon(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Bouwbedrijf']);
        $project = Project::query()->create([
            'project_number' => '250100099',
            'customer_id' => $customer->id,
            'name' => 'Nieuwbouw',
            'status' => 'gepland',
        ]);

        $this->actingAs($user)->get(route('projects.winkel.werkbon', $project))->assertNotFound();
        $this->actingAs($user)->get(route('projects.winkel.werkbon.pdf', $project))->assertNotFound();
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

    private function makeShopWorker(string $name): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'specialty' => 'PVC',
            'active' => true,
        ]);
    }

    private function bookWorker(Worker $worker, string $start, string $end): WorkerAssignment
    {
        $customer = Customer::query()->create(['name' => 'Andere Klant']);
        $project = Project::query()->create([
            'project_number' => '2602000'.fake()->unique()->numerify('##'),
            'customer_id' => $customer->id,
            'name' => 'Ander werk',
            'status' => 'gepland',
            'planned_start_date' => $start,
            'planned_end_date' => $end,
        ]);
        $item = $project->workItems()->create([
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'gepland',
        ]);
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'people_count' => 1,
        ]);
        $assignment->applySchedule(Carbon::parse($start), Carbon::parse($end), '08:00', '16:00');
        $assignment->save();

        return $assignment;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'customer_name' => 'Jansen',
            'city' => 'Hengelo',
            'work_activity_ids' => [$this->activity('screens')->id],
        ];
    }

    private function activity(string $slug): WorkActivity
    {
        return WorkActivity::query()->where('slug', $slug)->firstOrFail();
    }

    private function addActivity(string $name, string $categorySlug): WorkActivity
    {
        $category = WorkActivityCategory::query()->where('slug', $categorySlug)->firstOrFail();

        return WorkActivity::query()->create([
            'work_activity_category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => 99,
            'is_active' => true,
        ]);
    }
}
