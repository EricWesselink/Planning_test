<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkerWhatsAppContactExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('workers.whatsapp-contacts.export'))
            ->assertRedirect(route('login'));
    }

    public function test_vakman_is_forbidden_from_exporting_contacts(): void
    {
        $team = $this->ownStaff('Nick Seine', '06 12345678');
        $user = User::factory()->vakman($team->id)->create();

        $this->actingAs($user)
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertForbidden();
    }

    public function test_planner_sees_the_export_button_on_vakmensen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('WhatsApp contacten exporteren')
            ->assertSee('href="'.route('workers.whatsapp-contacts.export').'"', false);
    }

    public function test_uitvoerder_sees_the_export_button_and_can_download(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $this->ownStaff('Nick Seine', '0612345678');

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('WhatsApp contacten exporteren')
            ->assertDontSee('PDF uitlezen');

        $this->actingAs($user)
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertDownload('nicon-vakmannen.vcf');
    }

    public function test_downloads_vcard_for_active_own_staff_with_normalized_mobile(): void
    {
        $user = User::factory()->create();
        $nick = $this->ownStaff('Nick Seine', '06 12345678');
        $this->ownStaff('José García', '06-87654321');

        $response = $this->actingAs($user)
            ->get(route('workers.whatsapp-contacts.export'));

        $response
            ->assertOk()
            ->assertDownload('nicon-vakmannen.vcf')
            ->assertHeader('Content-Type', 'text/vcard; charset=UTF-8');

        $vcf = $response->streamedContent();
        $this->assertStringContainsString("BEGIN:VCARD\r\nVERSION:3.0\r\n", $vcf);
        $this->assertStringContainsString('FN;CHARSET=UTF-8:Nicon - Nick Seine', $vcf);
        $this->assertStringContainsString('TEL;TYPE=CELL:+31612345678', $vcf);
        $this->assertStringContainsString('FN;CHARSET=UTF-8:Nicon - José García', $vcf);
        $this->assertStringContainsString('TEL;TYPE=CELL:+31687654321', $vcf);
        $this->assertSame('06 12345678', $nick->fresh()->phone);
        $this->assertSame('06 12345678', $nick->fresh()->crewPeople->first()?->phone);
    }

    #[DataProvider('messyStoredNames')]
    public function test_normalizes_tabs_spaces_and_inverted_last_names_in_the_vcard(string $stored, string $expected): void
    {
        $user = User::factory()->create();
        $worker = $this->ownStaff($stored, '0612345678');

        $vcf = $this->actingAs($user)
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertDownload('nicon-vakmannen.vcf')
            ->streamedContent();

        $this->assertStringContainsString('FN;CHARSET=UTF-8:'.$expected, $vcf);
        $this->assertStringContainsString('N;CHARSET=UTF-8:'.$expected.';;;;', $vcf);
        $this->assertSame($stored, $worker->fresh()->crewPeople->first()?->name);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function messyStoredNames(): array
    {
        return [
            'tab_and_initial' => ["Lukasz\tOzimek, L", 'Nicon - Lukasz Ozimek'],
            'double_spaces' => ['Nick  Seine', 'Nicon - Nick Seine'],
            'inverted_full_name' => ['Ozimek, Lukasz', 'Nicon - Lukasz Ozimek'],
            'utf8' => ['José  García', 'Nicon - José García'],
        ];
    }

    public function test_does_not_use_the_team_name_as_a_contact_name(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Peter Korteschiel',
            'employment_type' => 'eigen',
            'phone' => '0611111111',
            'people_count' => 1,
            'crew_members' => [
                ['name' => 'Peter', 'phone' => '0611111111', 'active' => true],
            ],
            'active' => true,
        ]);

        $vcf = $this->actingAs($user)
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertDownload('nicon-vakmannen.vcf')
            ->streamedContent();

        $this->assertStringContainsString('FN;CHARSET=UTF-8:Nicon - Peter', $vcf);
        $this->assertStringNotContainsString('Peter Korteschiel', $vcf);
        $this->assertSame('Peter', $worker->fresh()->crewPeople->first()?->name);
        $this->assertSame('Peter Korteschiel', $worker->fresh()->name);
    }

    public function test_does_not_export_a_team_without_named_people(): void
    {
        $user = User::factory()->create();
        Worker::query()->create([
            'name' => 'Team Wespro',
            'employment_type' => 'eigen',
            'phone' => '0611111111',
            'people_count' => 1,
            'crew_members' => [
                ['name' => '', 'phone' => '0611111111', 'active' => true],
            ],
            'active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasErrors(['whatsapp_contacts']);
    }

    public function test_does_not_use_a_login_that_copies_the_team_name(): void
    {
        $planner = User::factory()->create();
        $team = Worker::query()->create([
            'name' => 'Team 1 Nick',
            'employment_type' => 'eigen',
            'phone' => '0612345678',
            'people_count' => 1,
            'crew_members' => [
                ['name' => 'Nick', 'phone' => '0612345678', 'active' => true],
            ],
            'active' => true,
        ]);
        $nick = $team->fresh()->crewPeople->first();
        $this->assertNotNull($nick);
        User::factory()->vakman($team->id, $nick->id)->create(['name' => 'Team 1 Nick']);

        $vcf = $this->actingAs($planner)
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertDownload('nicon-vakmannen.vcf')
            ->streamedContent();

        $this->assertStringContainsString('FN;CHARSET=UTF-8:Nicon - Nick', $vcf);
        $this->assertStringNotContainsString('Team 1 Nick', $vcf);
    }

    public function test_prefers_vakman_person_name_over_a_shorter_account_name(): void
    {
        $planner = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Team 1 Nick',
            'employment_type' => 'eigen',
            'phone' => '0612345678',
            'people_count' => 1,
            'crew_members' => [
                ['name' => 'Nick Seine', 'phone' => '0612345678', 'active' => true],
            ],
            'active' => true,
        ]);
        $nick = $worker->fresh()->crewPeople->first();
        $this->assertNotNull($nick);
        User::factory()->vakman($worker->id, $nick->id)->create(['name' => 'Nick']);

        $vcf = $this->actingAs($planner)
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertDownload('nicon-vakmannen.vcf')
            ->streamedContent();

        $this->assertStringContainsString('FN;CHARSET=UTF-8:Nicon - Nick Seine', $vcf);
        $this->assertStringNotContainsString('FN;CHARSET=UTF-8:Nicon - Nick\r\n', $vcf);
        $this->assertSame('Nick', $nick->fresh()->user?->name);
        $this->assertSame('Nick Seine', $nick->fresh()->name);
    }

    public function test_uses_the_login_full_name_when_the_crew_name_is_only_a_first_name(): void
    {
        $user = User::factory()->create();
        $team = Worker::query()->create([
            'name' => 'Team 1 Nick',
            'employment_type' => 'eigen',
            'phone' => '0612345678',
            'people_count' => 1,
            'crew_members' => [
                ['name' => 'Nick', 'phone' => '0612345678', 'active' => true],
            ],
            'active' => true,
        ]);
        $nick = $team->fresh()->crewPeople->first();
        $this->assertNotNull($nick);
        User::factory()->vakman($team->id, $nick->id)->create(['name' => 'Nick Seine']);

        $vcf = $this->actingAs($user)
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertDownload('nicon-vakmannen.vcf')
            ->streamedContent();

        $this->assertStringContainsString('FN;CHARSET=UTF-8:Nicon - Nick Seine', $vcf);
        $this->assertStringNotContainsString('Team 1 Nick', $vcf);
    }

    public function test_keeps_a_first_name_when_no_fuller_name_is_available(): void
    {
        $user = User::factory()->create();
        Worker::query()->create([
            'name' => 'Team 3 Arek',
            'employment_type' => 'eigen',
            'phone' => '0611111111',
            'people_count' => 1,
            'crew_members' => [
                ['name' => 'Arek', 'phone' => '0611111111', 'active' => true],
            ],
            'active' => true,
        ]);

        $vcf = $this->actingAs($user)
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertDownload('nicon-vakmannen.vcf')
            ->streamedContent();

        $this->assertStringContainsString('FN;CHARSET=UTF-8:Nicon - Arek', $vcf);
        $this->assertStringNotContainsString('Team 3 Arek', $vcf);
    }

    public function test_omits_zzp_and_onderaannemers(): void
    {
        $user = User::factory()->create();
        $this->ownStaff('Peter Korteschiel', '0611111111');
        $this->makeWorker('Sander ZZP', 'zzp', '0622222222');
        $this->makeWorker('Bouwploeg', 'onderaannemer', '0633333333');

        $vcf = $this->actingAs($user)
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertDownload('nicon-vakmannen.vcf')
            ->streamedContent();

        $this->assertStringContainsString('Nicon - Peter Korteschiel', $vcf);
        $this->assertStringContainsString('TEL;TYPE=CELL:+31611111111', $vcf);
        $this->assertStringNotContainsString('Sander ZZP', $vcf);
        $this->assertStringNotContainsString('Bouwploeg', $vcf);
        $this->assertStringNotContainsString('+31622222222', $vcf);
        $this->assertStringNotContainsString('+31633333333', $vcf);
    }

    public function test_omits_inactive_workers_and_crew_members(): void
    {
        $user = User::factory()->create();
        $this->ownStaff('Arek Actief', '0611111111');
        $this->ownStaff('Inactief Team', '0622222222', active: false);

        $team = Worker::query()->create([
            'name' => 'Team 1 Nick',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Mahmoud Khairallah Sulaiman', 'phone' => '0633333333', 'active' => true],
                ['name' => 'Mohammed', 'phone' => '0644444444', 'active' => false],
            ],
            'active' => true,
        ]);
        $this->assertNotNull($team->id);

        $vcf = $this->actingAs($user)
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertDownload('nicon-vakmannen.vcf')
            ->streamedContent();

        $this->assertStringContainsString('Nicon - Arek Actief', $vcf);
        $this->assertStringContainsString('Nicon - Mahmoud Khairallah Sulaiman', $vcf);
        $this->assertStringNotContainsString('Inactief Team', $vcf);
        $this->assertStringNotContainsString('Mohammed', $vcf);
        $this->assertStringNotContainsString('+31622222222', $vcf);
        $this->assertStringNotContainsString('+31644444444', $vcf);
    }

    public function test_omits_people_without_a_phone_and_reports_their_names(): void
    {
        $user = User::factory()->create();
        $this->ownStaff('Nick Seine', '06 12345678');
        $this->ownStaff('Peter Korteschiel', '');

        $response = $this->actingAs($user)
            ->get(route('workers.whatsapp-contacts.export'));

        $vcf = $response->streamedContent();

        $response
            ->assertDownload('nicon-vakmannen.vcf')
            ->assertSessionHas(
                'whatsapp_contacts_skipped',
                'Niet opgenomen (geen telefoonnummer): Peter Korteschiel.',
            );

        $this->assertStringContainsString('Nicon - Nick Seine', $vcf);
        $this->assertStringNotContainsString('Peter Korteschiel', $vcf);

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('Niet opgenomen (geen telefoonnummer): Peter Korteschiel.');
    }

    public function test_redirects_when_nobody_has_a_phone(): void
    {
        $user = User::factory()->create();
        $this->ownStaff('Peter Korteschiel', '');

        $this->actingAs($user)
            ->get(route('workers.whatsapp-contacts.export'))
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasErrors([
                'whatsapp_contacts' => 'Geen contacten geëxporteerd. Geen telefoonnummer bij: Peter Korteschiel.',
            ]);
    }

    private function ownStaff(string $name, string $phone, bool $active = true): Worker
    {
        return $this->makeWorker($name, 'eigen', $phone, $active);
    }

    private function makeWorker(string $name, string $type, string $phone, bool $active = true): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => $type,
            'phone' => $phone !== '' ? $phone : null,
            'people_count' => 1,
            'crew_members' => [
                ['name' => $name, 'phone' => $phone, 'active' => true],
            ],
            'active' => $active,
        ]);
    }
}
