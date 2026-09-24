<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VoucherType;
use App\Mail\WorkerPlanningInviteMail;
use App\Models\CrewMember;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkerRate;
use App\Models\WorkItem;
use App\Services\PlanningBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_page_lists_email_color_and_intake_work(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Eric',
            'employment_type' => 'eigen',
            'phone' => '06 11111111',
            'email' => 'eric@niconvloeren.nl',
            'color' => '#c2410c',
            'address' => 'Industrieweg 8',
            'postal_code' => '8013 PM',
            'city' => 'Zwolle',
            'specialty' => 'Inmeten, Werkopname',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('Eric')
            ->assertSee('eric@niconvloeren.nl')
            ->assertSee('Inmeten')
            ->assertSee('Werkopname')
            ->assertSee('#c2410c', false)
            ->assertDontSee('Afgesproken prijzen')
            ->assertDontSee('Opdrachtbon')
            ->assertDontSee('>Opdrachten</h2>', false)
            ->assertDontSee('Uitgevoerd')
            ->assertDontSee('Open in productie')
            ->assertDontSee('Nog geen productie')
            ->assertDontSee('1 persoon')
            ->assertDontSee('Vink vrijdagen af')
            ->assertDontSee('Helemaal niet beschikbaar')
            ->assertDontSee('Beschikbaarheid')
            ->assertSee('Personeel')
            ->assertSee('class="space-y-4 hidden"', false);
    }

    public function test_planner_can_update_naw_and_email_for_a_zzp(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Sander',
            'employment_type' => 'zzp',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), [
                'name' => 'Sander',
                'employment_type' => 'zzp',
                'company' => 'Sander Vloeren',
                'phone' => '06 11111111',
                'email' => 'sander@example.nl',
                'address' => 'Industrieweg 8',
                'postal_code' => '8013 PM',
                'city' => 'Zwolle',
                'active' => '1',
            ])
            ->assertRedirect(route('workers.show', $worker));

        $worker->refresh();
        $this->assertSame('sander@example.nl', $worker->email);
        $this->assertSame('Sander Vloeren', $worker->company);
        $this->assertSame('Industrieweg 8', $worker->address);
        $this->assertSame('8013 PM', $worker->postal_code);
        $this->assertSame('Zwolle', $worker->city);
    }

    public function test_saving_an_own_employee_keeps_existing_company_and_address(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Eric',
            'employment_type' => 'eigen',
            'company' => 'Nicon Vloeren',
            'contact_name' => 'Kantoor',
            'address' => 'Industrieweg 8',
            'postal_code' => '8013 PM',
            'city' => 'Zwolle',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), [
                'name' => 'Eric',
                'employment_type' => 'eigen',
                'company' => '',
                'contact_name' => '',
                'address' => '',
                'postal_code' => '',
                'city' => '',
                'email' => 'eric@niconvloeren.nl',
                'active' => '1',
            ])
            ->assertRedirect(route('workers.show', $worker));

        $worker->refresh();
        $this->assertSame('eric@niconvloeren.nl', $worker->email);
        $this->assertSame('Nicon Vloeren', $worker->company);
        $this->assertSame('Kantoor', $worker->contact_name);
        $this->assertSame('Industrieweg 8', $worker->address);
        $this->assertSame('8013 PM', $worker->postal_code);
        $this->assertSame('Zwolle', $worker->city);
    }

    public function test_zzp_page_shows_company_address_and_agreed_prices(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Harm Wesselink',
            'employment_type' => 'zzp',
            'company' => 'Harm Wesselink',
            'contact_name' => 'Harm',
            'address' => 'Kerkstraat 2',
            'postal_code' => '8011 AA',
            'city' => 'Zwolle',
            'specialty' => 'Primen & egaliseren',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('Harm Wesselink')
            ->assertSee('Kerkstraat 2')
            ->assertSee('8011 AA')
            ->assertSee('Zwolle')
            ->assertSee('Harm')
            ->assertSee('Afgesproken prijzen')
            ->assertSee('Uurtarief')
            ->assertSee('>Opdrachten</h2>', false)
            ->assertSee('Uitgevoerd')
            ->assertSee('Open in productie')
            ->assertSee('1 persoon')
            ->assertSee('Helemaal niet beschikbaar')
            ->assertDontSee('class="space-y-4 hidden"', false);
    }

    public function test_switching_an_own_employee_to_zzp_shows_stored_company_and_prices(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Eric',
            'employment_type' => 'eigen',
            'company' => 'Eric Vloeren',
            'address' => 'Industrieweg 8',
            'postal_code' => '8013 PM',
            'city' => 'Zwolle',
            'specialty' => 'Inmeten, Werkopname',
            'active' => true,
        ]);
        WorkerRate::query()->create([
            'worker_id' => $worker->id,
            'specialty' => 'uurtarief',
            'unit' => 'uren',
            'unit_price' => 45,
        ]);

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), [
                'name' => 'Eric',
                'employment_type' => 'zzp',
                'active' => '1',
                'specialties' => ['inmeten', 'werkopname'],
            ])
            ->assertRedirect(route('workers.show', $worker));

        $this->actingAs($user)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('Eric Vloeren')
            ->assertSee('Industrieweg 8')
            ->assertSee('Afgesproken prijzen')
            ->assertSee('45.00')
            ->assertSee('Inmeten')
            ->assertSee('Werkopname');
    }

    public function test_create_form_asks_for_vakkennis(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workers.create'))
            ->assertOk()
            ->assertSee('Nieuwe vakman')
            ->assertSee('Vakkennis')
            ->assertSee('Primen & egaliseren')
            ->assertSee('PVC')
            ->assertSee('Plinten')
            ->assertSee('Inmeten')
            ->assertSee('Werkopname')
            ->assertSee('Montage')
            ->assertSee('Reparatie')
            ->assertSee('Service')
            ->assertSee('E-mail (inlog, optioneel)')
            ->assertSee('activatielink')
            ->assertSee('Stuur uitnodiging voor de planning')
            ->assertSee('class="space-y-4 hidden"', false)
            ->assertDontSee('name="team_ids[]"', false)
            ->assertDontSee('Kleur in de planning')
            ->assertDontSee('name="color"', false);
    }

    public function test_planner_can_create_worker_with_naw(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('workers.store'), [
                'name' => 'Sander',
                'employment_type' => 'zzp',
                'company' => 'Sander Vloeren',
                'email' => 'sander@example.nl',
                'password' => 'wachtwoord123',
                'password_confirmation' => 'wachtwoord123',
                'address' => 'Kerkstraat 2',
                'postal_code' => '8011 AA',
                'city' => 'Zwolle',
                'specialties' => ['pvc'],
                'active' => '1',
            ])
            ->assertRedirect(route('workers.index'));

        $worker = Worker::query()->where('name', 'Sander')->first();
        $this->assertNotNull($worker);
        $this->assertSame('sander@example.nl', $worker->email);
        $login = User::query()->where('email', 'sander@example.nl')->first();
        $this->assertNotNull($login);
        $this->assertSame(UserRole::Vakman, $login->role);
        $this->assertSame($worker->id, $login->worker_id);
        $this->assertFalse(Hash::check('wachtwoord123', $login->password));
        $this->assertSame('Zwolle', $worker->city);
        $this->assertSame('PVC', $worker->specialty);
        $this->assertNotNull($worker->color);
        $this->assertSame(1, preg_match('/^#[0-9a-f]{6}$/', $worker->planColor()));
    }

    public function test_new_workers_get_distinct_plan_colors(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('workers.store'), [
                'name' => 'Peter',
                'employment_type' => 'eigen',
                'people_count' => '1',
            ])
            ->assertRedirect(route('workers.index'));

        $this->actingAs($user)
            ->post(route('workers.store'), [
                'name' => 'Team Wespro',
                'employment_type' => 'zzp',
                'people_count' => '1',
            ])
            ->assertRedirect(route('workers.index'));

        $colors = Worker::query()->orderBy('id')->get()->map->planColor()->all();

        $this->assertCount(2, $colors);
        $this->assertCount(2, array_unique($colors));
    }

    public function test_duplicate_plan_colors_are_separated(): void
    {
        Worker::withoutEvents(function (): void {
            Worker::query()->create([
                'name' => 'Peter',
                'employment_type' => 'eigen',
                'color' => '#7c3aed',
                'active' => true,
            ]);
            Worker::query()->create([
                'name' => 'Team Wespro',
                'employment_type' => 'zzp',
                'color' => '#7c3aed',
                'active' => true,
            ]);
        });

        Worker::reassignCollidingColors();

        $colors = Worker::query()->orderBy('id')->get()->map->planColor()->all();

        $this->assertCount(2, array_unique($colors));
        $this->assertContains('#7c3aed', $colors);
    }

    public function test_vakmensen_overview_shows_type_people_and_team_names(): void
    {
        $user = User::factory()->create();
        $this->listedWorker([
            'name' => 'Jansen Vloeren',
            'employment_type' => 'zzp',
            'people_count' => 2,
            'crew_names' => 'Kees, Piet',
            'specialty' => 'PVC, Plinten',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Jansen Vloeren')
            ->assertSee('ZZP')
            ->assertSee('2 personen')
            ->assertSee('Kees, Piet')
            ->assertSee('PVC, Plinten')
            ->assertSee('Primen & egaliseren')
            ->assertSee('Naam van het team')
            ->assertSee('E-mail (inlog)')
            ->assertSee('activatielink')
            ->assertSee('Stuur uitnodiging voor de planning')
            ->assertSee('Vakkennis van dit team')
            ->assertSee('Inmeten')
            ->assertSee('Werkopname')
            ->assertSee('Montage')
            ->assertSee('Reparatie')
            ->assertSee('Service')
            ->assertSee('Zoeken op vakkennis')
            ->assertSee('name="specialties[]"', false)
            ->assertSee('Inlog is optioneel')
            ->assertSee('name="vakkennis[]"', false)
            ->assertSee('Alles staat aan')
            ->assertSee('Alles uitvinken')
            ->assertSee('Onderdeel toevoegen')
            ->assertSee('Inactief zetten')
            ->assertSee('Verwijderen')
            ->assertDontSee('Namen van het team')
            ->assertDontSee('Naam persoon 1')
            ->assertDontSee('Ploeg toevoegen')
            ->assertDontSee('Naam van de ploeg');
    }

    public function test_vakkennis_filter_shows_only_teams_that_can_do_the_selected_work(): void
    {
        $user = User::factory()->create();
        $this->listedWorker([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'specialty' => 'Primen & egaliseren',
            'active' => true,
        ]);
        $this->listedWorker([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'specialty' => 'PVC',
            'active' => true,
        ]);
        $this->listedWorker([
            'name' => 'Peter',
            'employment_type' => 'eigen',
            'specialty' => 'Vinyl, Tapijt',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Albert')
            ->assertSee('Kees Jansen')
            ->assertSee('Peter');

        $this->actingAs($user)
            ->get(route('workers.index', ['zoeken' => '1', 'vakkennis' => ['pvc']]))
            ->assertOk()
            ->assertSee('Kees Jansen')
            ->assertDontSee('Albert')
            ->assertDontSee('Peter')
            ->assertSee('Alles aanvinken');
    }

    public function test_vakkennis_filter_keeps_teams_that_can_do_any_selected_skill(): void
    {
        $user = User::factory()->create();
        $this->listedWorker([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'specialty' => 'Primen & egaliseren',
            'active' => true,
        ]);
        $this->listedWorker([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'specialty' => 'PVC',
            'active' => true,
        ]);
        $this->listedWorker([
            'name' => 'Peter',
            'employment_type' => 'eigen',
            'specialty' => 'Vinyl, Tapijt',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.index', ['zoeken' => '1', 'vakkennis' => ['pvc', 'vinyl']]))
            ->assertOk()
            ->assertSee('Kees Jansen')
            ->assertSee('Peter')
            ->assertDontSee('Albert');
    }

    public function test_vakkennis_filter_without_choices_shows_no_teams(): void
    {
        $user = User::factory()->create();
        Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'specialty' => 'PVC',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.index', ['zoeken' => '1']))
            ->assertOk()
            ->assertDontSee('Albert')
            ->assertSee('Geen teams met deze vakkennis.')
            ->assertSee('Alles aanvinken');
    }

    public function test_planner_can_add_a_vakkennis_onderdeel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.specialties.store'), [
                'onderdeel' => 'Parket',
            ])
            ->assertRedirect(route('workers.index'));

        $this->assertDatabaseHas('specialty_options', [
            'name' => 'Parket',
        ]);

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('value="Parket"', false)
            ->assertSee('Onderdeel toevoegen');
    }

    public function test_uitvoerder_cannot_add_vakkennis(): void
    {
        $user = User::factory()->uitvoerder()->create();

        $this->actingAs($user)
            ->post(route('workers.specialties.store'), [
                'onderdeel' => 'Parket',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('specialty_options', [
            'name' => 'Parket',
        ]);
    }

    public function test_vakkennis_onderdeel_with_a_comma_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.specialties.store'), [
                'onderdeel' => 'Parket, hout',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasErrors('onderdeel');

        $this->assertDatabaseMissing('specialty_options', [
            'name' => 'Parket, hout',
        ]);
    }

    public function test_added_vakkennis_is_escaped_on_the_overview(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('workers.specialties.store'), [
                'onderdeel' => '<script>alert(1)</script>',
            ])
            ->assertRedirect(route('workers.index'));

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_planner_can_save_inmeten_montage_reparatie_and_service_vakkennis(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.store'), [
                'name' => 'Team Service',
                'employment_type' => 'eigen',
                'people_count' => '1',
                'specialties' => ['inmeten', 'montage', 'reparatie', 'service'],
            ])
            ->assertRedirect(route('workers.index'));

        $this->assertDatabaseHas('workers', [
            'name' => 'Team Service',
            'specialty' => 'Inmeten, Montage, Reparatie, Service',
        ]);
    }

    public function test_planner_can_add_worker_from_the_overview(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.store'), [
                'name' => 'Jansen Vloeren',
                'employment_type' => 'zzp',
                'people_count' => '2',
                'crew_names' => 'Kees, Piet',
                'specialties' => ['pvc', 'plinten'],
            ])
            ->assertRedirect(route('workers.index'));

        $this->assertDatabaseHas('workers', [
            'name' => 'Jansen Vloeren',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'people_count' => 2,
            'crew_names' => 'Kees, Piet',
            'specialty' => 'PVC, Plinten',
        ]);
        $this->assertDatabaseMissing('users', ['name' => 'Jansen Vloeren']);

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Jansen Vloeren')
            ->assertSee('Nog geen inlog')
            ->assertSee('PVC, Plinten');
    }

    public function test_planner_can_add_a_worker_with_a_temporary_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.store'), [
                'name' => 'Team Wespro',
                'employment_type' => 'zzp',
                'people_count' => '3',
                'specialties' => ['vinyl'],
                'email' => 'wespro@niconvloeren.nl',
                'password' => 'tijdelijk1',
                'password_confirmation' => 'tijdelijk1',
            ])
            ->assertRedirect(route('workers.index'));

        $worker = Worker::query()->where('name', 'Team Wespro')->first();
        $this->assertNotNull($worker);
        $this->assertSame('Vinyl', $worker->specialty);
        $this->assertSame(3, $worker->people_count);
        $login = User::query()->where('email', 'wespro@niconvloeren.nl')->first();
        $this->assertNotNull($login);
        $this->assertSame(UserRole::Vakman, $login->role);
        $this->assertSame($worker->id, $login->worker_id);
        $this->assertFalse(Hash::check('tijdelijk1', $login->password));

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Team Wespro')
            ->assertDontSee('Nog geen inlog');
    }

    public function test_planning_invite_is_sent_when_requested(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.store'), [
                'name' => 'Team Wespro',
                'employment_type' => 'zzp',
                'people_count' => '2',
                'specialties' => ['pvc'],
                'email' => 'wespro@niconvloeren.nl',
                'password' => 'tijdelijk1',
                'password_confirmation' => 'tijdelijk1',
                'invite' => '1',
            ])
            ->assertRedirect(route('workers.index'));

        Mail::assertSent(WorkerPlanningInviteMail::class, function (WorkerPlanningInviteMail $mail): bool {
            return $mail->hasTo('wespro@niconvloeren.nl')
                && str_contains($mail->activationUrl, '/activeren/')
                && ! str_contains($mail->activationUrl, 'tijdelijk1')
                && $mail->worker->name === 'Team Wespro';
        });
    }

    public function test_planning_invite_without_login_email_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.store'), [
                'name' => 'Team Wespro',
                'employment_type' => 'eigen',
                'people_count' => '1',
                'invite' => '1',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasErrors([
                'email' => 'Vul een e-mailadres in voor de inlog.',
            ]);

        $this->assertDatabaseMissing('workers', ['name' => 'Team Wespro']);
    }

    public function test_adding_a_worker_with_a_taken_email_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'wespro@niconvloeren.nl']);

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.store'), [
                'name' => 'Team Wespro',
                'employment_type' => 'eigen',
                'people_count' => '1',
                'email' => 'wespro@niconvloeren.nl',
                'password' => 'wachtwoord123',
                'password_confirmation' => 'wachtwoord123',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasErrors(['email' => 'Dit e-mailadres is al in gebruik.']);

        $this->assertDatabaseMissing('workers', ['name' => 'Team Wespro']);
    }

    public function test_planning_picker_defaults_to_the_worker_people_count(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Jansen Vloeren',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'people_count' => 2,
            'active' => true,
        ]);
        $this->giveLogin($worker);

        $this->actingAs($user)
            ->get(route('planning'))
            ->assertOk()
            ->assertSee('value="worker:'.$worker->id.'" data-men="2"', false)
            ->assertSee('Kies vakman of team')
            ->assertSee('>Jansen Vloeren</option>', false)
            ->assertDontSee('ZZP Jansen Vloeren')
            ->assertDontSee('Kies vakman of ploeg');
    }

    public function test_zero_personen_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.store'), [
                'name' => 'Albert',
                'employment_type' => 'eigen',
                'people_count' => '0',
                'email' => 'albert@niconvloeren.nl',
                'password' => 'wachtwoord123',
                'password_confirmation' => 'wachtwoord123',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasErrors('people_count');

        $this->assertDatabaseMissing('workers', ['name' => 'Albert']);
    }

    public function test_uitvoerder_cannot_create_a_worker(): void
    {
        $user = User::factory()->uitvoerder()->create();

        $this->actingAs($user)
            ->post(route('workers.store'), [
                'name' => 'Albert',
                'employment_type' => 'eigen',
                'people_count' => '1',
            ])
            ->assertForbidden();
    }

    public function test_planner_can_deactivate_and_reactivate_a_worker(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Fabian',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $login = User::factory()->vakman($worker->id)->create([
            'name' => 'Fabian',
            'email' => 'fabian@niconvloeren.nl',
        ]);

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->patch(route('workers.active.update', $worker), ['active' => '0'])
            ->assertRedirect(route('workers.index'));

        $this->assertFalse($worker->fresh()->active);
        $this->assertFalse($login->fresh()->active);

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Fabian')
            ->assertSee('Inactief')
            ->assertSee('Actief maken');

        $this->actingAs($user)
            ->get(route('planning'))
            ->assertOk()
            ->assertDontSee('value="worker:'.$worker->id.'"', false);

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->patch(route('workers.active.update', $worker), ['active' => '1'])
            ->assertRedirect(route('workers.index'));

        $this->assertTrue($worker->fresh()->active);
        $this->assertTrue($login->fresh()->active);
    }

    public function test_planner_can_delete_a_worker(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'N.D.N Seine (Nick)',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        User::factory()->vakman($worker->id)->create([
            'name' => 'Nick',
            'email' => 'nick@niconvloeren.nl',
        ]);

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->delete(route('workers.destroy', $worker))
            ->assertRedirect(route('workers.index'));

        $this->assertDatabaseMissing('workers', ['name' => 'N.D.N Seine (Nick)']);
        $this->assertDatabaseMissing('users', ['email' => 'nick@niconvloeren.nl']);
    }

    public function test_worker_with_vouchers_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Fabian',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'TMZ']);
        $project = Project::query()->create([
            'project_number' => '2026-001',
            'customer_id' => $customer->id,
            'name' => 'TMZ',
            'status' => 'in_uitvoering',
        ]);
        Voucher::query()->create([
            'number' => 'BON-2026-0001',
            'type' => VoucherType::Facturatie,
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'issued_on' => '2026-09-12',
            'total_amount' => 10,
        ]);

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->delete(route('workers.destroy', $worker))
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasErrors(['worker' => 'Fabian heeft bonnen. Zet het team inactief in plaats van te verwijderen.']);

        $this->assertDatabaseHas('workers', ['id' => $worker->id, 'name' => 'Fabian']);
    }

    public function test_uitvoerder_cannot_deactivate_or_delete_a_worker(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $worker = Worker::query()->create([
            'name' => 'Fabian',
            'employment_type' => 'zzp',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('workers.active.update', $worker), ['active' => '0'])
            ->assertForbidden();
        $this->actingAs($user)
            ->delete(route('workers.destroy', $worker))
            ->assertForbidden();

        $this->assertTrue($worker->fresh()->active);
        $this->assertDatabaseHas('workers', ['id' => $worker->id]);
    }

    public function test_planning_bar_uses_worker_color(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'color' => '#be123c',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'TMZ']);
        $project = Project::query()->create([
            'project_number' => '2024-118',
            'customer_id' => $customer->id,
            'name' => 'TMZ Meubelenbelt Fase 2',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);

        $request = Request::create('/planning', 'GET', ['week' => '2026-09-07']);
        $request->setUserResolver(fn () => $user);
        $data = app(PlanningBoardService::class)->build($request);
        $colors = collect($data['rows'])
            ->flatMap(fn ($row) => $row['children'] ?? [])
            ->flatMap(fn ($work) => $work['person_bars'] ?? [])
            ->pluck('color');

        $this->assertTrue($colors->contains('#be123c'));
        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('#be123c', false);
    }

    public function test_planning_bar_shows_personen_when_more_than_one_person(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'TMZ']);
        $project = Project::query()->create([
            'project_number' => '2024-118',
            'customer_id' => $customer->id,
            'name' => 'TMZ Meubelenbelt Fase 2',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
            'people_count' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Kees J. · 2 man')
            ->assertSee('Aantal personen')
            ->assertSee('2 vakmensen');
    }

    public function test_planning_bar_shows_one_person(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Jan',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'TMZ']);
        $project = Project::query()->create([
            'project_number' => '2024-118',
            'customer_id' => $customer->id,
            'name' => 'TMZ Meubelenbelt Fase 2',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-11',
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-11',
            'status' => 'in_uitvoering',
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-11',
            'hours_per_day' => 8,
            'people_count' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('bar-label" data-label-full="Jan · 1 man"', false)
            ->assertSee('1 vakman')
            ->assertDontSee('bar-label" data-label-full="Jan"', false);
    }

    public function test_planning_shows_the_team_name_instead_of_a_zzp_label(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Team Wespro',
            'employment_type' => 'zzp',
            'company' => 'Harm Wesselink',
            'people_count' => 2,
            'active' => true,
        ]);
        $this->giveLogin($worker);
        $customer = Customer::query()->create(['name' => 'TMZ']);
        $project = Project::query()->create([
            'project_number' => '2024-118',
            'customer_id' => $customer->id,
            'name' => 'TMZ Meubelenbelt Fase 2',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
            'people_count' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Team Wespro · 2 man')
            ->assertSee('>Team Wespro</option>', false)
            ->assertDontSee('ZZP Harm Wesselink')
            ->assertDontSee('Kies vakman of ploeg');
    }

    public function test_ploegen_live_under_vakmensen_not_in_the_menu(): void
    {
        $user = User::factory()->create();
        $team = Team::query()->create(['name' => 'Ploeg 1', 'active' => true]);
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $team->workers()->attach($worker->id);
        $this->giveLogin($worker);

        $this->actingAs($user)
            ->get(route('teams.index'))
            ->assertRedirect(route('workers.index'));

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Vakmensen / ZZP')
            ->assertSee('Albert')
            ->assertDontSee('Ploeg toevoegen')
            ->assertDontSee('href="'.url('/ploegen').'"', false)
            ->assertDontSee('href="'.url('/voortgang').'"', false)
            ->assertSee('Productie');
    }

    public function test_worker_form_does_not_offer_ploegen(): void
    {
        $user = User::factory()->create();
        $team = Team::query()->create(['name' => 'Ploeg 1', 'active' => true]);
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->assertDontSee('Ploeg 1')
            ->assertDontSee('name="team_ids[]"', false)
            ->assertSee('name="specialties[]"', false)
            ->assertSee('Primen & egaliseren')
            ->assertSee('Onderdeel toevoegen')
            ->assertDontSee('Kleur in de planning')
            ->assertDontSee('name="color"', false);
    }

    public function test_planner_can_save_projectstoffering_vakkennis(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), [
                'name' => 'Albert',
                'employment_type' => 'eigen',
                'active' => '1',
                'specialties' => ['pvc', 'plinten'],
            ])
            ->assertRedirect(route('workers.show', $worker));

        $this->assertSame('PVC, Plinten', $worker->fresh()->specialty);
    }

    public function test_planner_can_save_custom_vakkennis(): void
    {
        $user = User::factory()->create();
        $worker = $this->listedWorker([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), [
                'name' => 'Albert',
                'employment_type' => 'eigen',
                'active' => '1',
                'specialties' => ['pvc', 'Parket'],
            ])
            ->assertRedirect(route('workers.show', $worker));

        $this->assertSame('PVC, Parket', $worker->fresh()->specialty);

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('value="Parket"', false)
            ->assertSee('PVC, Parket');
    }

    public function test_edit_form_shows_a_name_and_phone_field_for_each_person(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Team Wespro',
            'employment_type' => 'zzp',
            'people_count' => 3,
            'crew_names' => 'r Korteschiel, Sietse van Dijk, Arek Gorzynski',
            'phone' => '0610767167',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('name="crew_members[0][name]"', false)
            ->assertSee('name="crew_members[1][name]"', false)
            ->assertSee('name="crew_members[2][name]"', false)
            ->assertSee('name="crew_members[0][phone]"', false)
            ->assertSee('name="crew_members[2][phone]"', false)
            ->assertSee('Naam persoon 1')
            ->assertSee('Naam persoon 3')
            ->assertSee('r Korteschiel')
            ->assertSee('Arek Gorzynski')
            ->assertSee('0610767167')
            ->assertDontSee('name="crew_names"', false)
            ->assertDontSee('name="phone"', false);
    }

    public function test_planner_can_save_a_name_and_phone_for_each_person(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Team Wespro',
            'employment_type' => 'zzp',
            'people_count' => 1,
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), [
                'name' => 'Team Wespro',
                'employment_type' => 'zzp',
                'people_count' => '3',
                'crew_members' => [
                    ['name' => 'r Korteschiel', 'phone' => '0610767167'],
                    ['name' => 'Sietse van Dijk', 'phone' => '0612345678'],
                    ['name' => 'Arek Gorzynski', 'phone' => '0698765432'],
                ],
                'active' => '1',
            ])
            ->assertRedirect(route('workers.show', $worker));

        $worker->refresh();
        $this->assertSame(3, $worker->people_count);
        $this->assertSame('r Korteschiel, Sietse van Dijk, Arek Gorzynski', $worker->crew_names);
        $this->assertSame('0610767167', $worker->phone);
        $this->assertSame([
            ['name' => 'r Korteschiel', 'phone' => '0610767167'],
            ['name' => 'Sietse van Dijk', 'phone' => '0612345678'],
            ['name' => 'Arek Gorzynski', 'phone' => '0698765432'],
        ], $worker->crewMembers());
    }

    public function test_saving_fewer_people_drops_extra_crew_members(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Team Wespro',
            'employment_type' => 'zzp',
            'people_count' => 3,
            'crew_members' => [
                ['name' => 'r Korteschiel', 'phone' => '0610767167'],
                ['name' => 'Sietse van Dijk', 'phone' => '0612345678'],
                ['name' => 'Arek Gorzynski', 'phone' => '0698765432'],
            ],
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), [
                'name' => 'Team Wespro',
                'employment_type' => 'zzp',
                'people_count' => '1',
                'crew_members' => [
                    ['name' => 'r Korteschiel', 'phone' => '0610767167'],
                    ['name' => 'Sietse van Dijk', 'phone' => '0612345678'],
                    ['name' => 'Arek Gorzynski', 'phone' => '0698765432'],
                ],
                'active' => '1',
            ])
            ->assertRedirect(route('workers.show', $worker));

        $worker->refresh();
        $this->assertSame(1, $worker->people_count);
        $this->assertSame('r Korteschiel', $worker->crew_names);
        $this->assertSame([
            ['name' => 'r Korteschiel', 'phone' => '0610767167'],
        ], $worker->crewMembers());
    }

    public function test_duplicate_teammate_names_are_stored_once(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Team 3',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Arek', 'phone' => ''],
                ['name' => 'Sietse', 'phone' => ''],
            ],
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('workers.update', $worker), [
                'name' => 'Team 3',
                'employment_type' => 'eigen',
                'people_count' => '3',
                'crew_members' => [
                    ['name' => 'Arek', 'phone' => ''],
                    ['name' => 'Sietse', 'phone' => ''],
                    ['name' => 'Sietse', 'phone' => ''],
                ],
                'active' => '1',
            ])
            ->assertRedirect(route('workers.show', $worker));

        $worker->refresh();
        $this->assertSame(2, $worker->people_count);
        $this->assertSame('Arek, Sietse', $worker->crew_names);
        $this->assertSame([
            ['name' => 'Arek', 'phone' => ''],
            ['name' => 'Sietse', 'phone' => ''],
        ], $worker->crewMembers());
        $this->assertSame(['Arek', 'Sietse'], $worker->crewPeople()->pluck('name')->all());
    }

    public function test_team_list_shows_sietse_once_when_the_roster_has_a_duplicate(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Team 3 Arek',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Arek', 'phone' => ''],
                ['name' => 'Sietse', 'phone' => ''],
            ],
            'active' => true,
        ]);
        CrewMember::query()->create([
            'worker_id' => $worker->id,
            'name' => 'Sietse',
            'sort_order' => 2,
        ]);
        $worker->forceFill([
            'people_count' => 3,
            'crew_names' => 'Arek, Sietse, Sietse',
            'crew_members' => [
                ['name' => 'Arek', 'phone' => ''],
                ['name' => 'Sietse', 'phone' => ''],
                ['name' => 'Sietse', 'phone' => ''],
            ],
        ])->saveQuietly();

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Team 3 Arek')
            ->assertSee('Arek, Sietse')
            ->assertDontSee('Arek, Sietse, Sietse');

        $html = $this->actingAs($user)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('Arek')
            ->assertSee('Sietse')
            ->getContent();
        $this->assertSame(1, preg_match_all('/name="crew_members\[\d+\]\[name\]"[^>]*value="Sietse"/', $html));

        $worker->collapseDuplicateCrewPeople();
        $worker->refresh();
        $this->assertSame(2, $worker->people_count);
        $this->assertSame('Arek, Sietse', $worker->crew_names);
        $this->assertSame(['Arek', 'Sietse'], $worker->crewPeople()->pluck('name')->all());
    }

    public function test_vakkennis_with_a_comma_is_rejected(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('workers.show', $worker))
            ->patch(route('workers.update', $worker), [
                'name' => 'Albert',
                'employment_type' => 'eigen',
                'active' => '1',
                'specialties' => ['Parket, hout'],
            ])
            ->assertRedirect(route('workers.show', $worker))
            ->assertSessionHasErrors('specialties.0');

        $this->assertNull($worker->fresh()->specialty);
    }

    public function test_custom_vakkennis_is_escaped_on_the_overview(): void
    {
        $user = User::factory()->create();
        Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'specialty' => '<script>alert(1)</script>',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_vakmensen_overview_shows_teams_without_a_login(): void
    {
        $user = User::factory()->create();
        Worker::query()->create([
            'name' => 'Zonder Inlog',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $this->listedWorker([
            'name' => 'Nick Seine',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Nick Seine')
            ->assertSee('Zonder Inlog')
            ->assertSee('Nog geen inlog')
            ->assertSee('Inlog via e-mail mag nu of later');
    }

    public function test_planner_can_add_a_login_later(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Team Wespro',
            'employment_type' => 'zzp',
            'people_count' => 2,
            'specialty' => 'PVC',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('Nog geen inlog')
            ->assertSee('Stuur uitnodiging voor de planning');

        $this->actingAs($user)
            ->from(route('workers.show', $worker))
            ->post(route('workers.login.store', $worker), [
                'email' => 'wespro@niconvloeren.nl',
                'password' => 'tijdelijk1',
                'password_confirmation' => 'tijdelijk1',
            ])
            ->assertRedirect(route('workers.show', $worker));

        $login = User::query()->where('email', 'wespro@niconvloeren.nl')->first();
        $this->assertNotNull($login);
        $this->assertSame($worker->id, $login->worker_id);
        $this->assertFalse(Hash::check('tijdelijk1', $login->password));
        $this->assertSame('wespro@niconvloeren.nl', $worker->fresh()->email);
    }

    public function test_uitvoerder_cannot_add_a_login_later(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $worker = Worker::query()->create([
            'name' => 'Team Wespro',
            'employment_type' => 'zzp',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('workers.login.store', $worker), [
                'email' => 'wespro@niconvloeren.nl',
                'password' => 'tijdelijk1',
                'password_confirmation' => 'tijdelijk1',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'wespro@niconvloeren.nl']);
    }

    public function test_office_account_replaces_vakman_login_on_the_worker_page(): void
    {
        $user = User::factory()->admin()->create([
            'name' => 'Eric Wesselink',
            'email' => 'e.wesselink@niconvloeren.nl',
        ]);
        $worker = Worker::query()->create([
            'name' => 'Eric Wesselink (Projectleider/Uitvoerder)',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('Heeft al een kantoorinlog als Beheerder')
            ->assertDontSee('Nog geen inlog')
            ->assertDontSee('Inlog opslaan');

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Eric Wesselink (Projectleider/Uitvoerder)')
            ->assertDontSee('Nog geen inlog');
    }

    public function test_office_email_on_the_worker_hides_the_vakman_login_form(): void
    {
        $planner = User::factory()->create(['name' => 'Marie']);
        User::factory()->admin()->create([
            'name' => 'Eric Wesselink',
            'email' => 'e.wesselink@niconvloeren.nl',
        ]);
        $worker = Worker::query()->create([
            'name' => 'Inmeet Eric',
            'email' => 'e.wesselink@niconvloeren.nl',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($planner)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('Heeft al een kantoorinlog als Beheerder')
            ->assertDontSee('Inlog opslaan');
    }

    public function test_planner_cannot_add_a_vakman_login_when_the_person_has_an_office_account(): void
    {
        $planner = User::factory()->create(['name' => 'Marie']);
        User::factory()->admin()->create([
            'name' => 'Eric Wesselink',
            'email' => 'e.wesselink@niconvloeren.nl',
        ]);
        $worker = Worker::query()->create([
            'name' => 'Eric Wesselink (Projectleider/Uitvoerder)',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($planner)
            ->from(route('workers.show', $worker))
            ->post(route('workers.login.store', $worker), [
                'email' => 'eric.vakman@niconvloeren.nl',
                'password' => 'tijdelijk1',
                'password_confirmation' => 'tijdelijk1',
            ])
            ->assertRedirect(route('workers.show', $worker));

        $this->assertDatabaseMissing('users', ['email' => 'eric.vakman@niconvloeren.nl']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function listedWorker(array $attributes): Worker
    {
        $worker = Worker::query()->create($attributes);
        $this->giveLogin($worker);

        return $worker;
    }

    private function giveLogin(Worker $worker): void
    {
        User::factory()->vakman($worker->id)->create(['name' => $worker->name]);
    }
}
