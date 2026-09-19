<?php

namespace Tests\Feature;

use App\Models\CrewMember;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkerLoginInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_page_has_an_invite_button_next_to_each_phone(): void
    {
        $planner = User::factory()->create();
        [$worker, $nick, $peter] = $this->makeTeamWithNickAndPeter();

        $html = $this->actingAs($planner)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('Inlogbericht maken')
            ->assertSee('Geen telefoonnummer ingevuld')
            ->getContent();

        $this->assertStringContainsString('form="vakman-login-invite-'.$nick->id.'"', $html);
        $this->assertStringContainsString('id="vakman-login-invite-'.$nick->id.'"', $html);
        $this->assertStringNotContainsString('form="vakman-login-invite-'.$peter->id.'"', $html);
    }

    public function test_guest_is_sent_to_login(): void
    {
        [$worker, $nick] = $this->makeNickTeam();

        $this->post(route('workers.login-invite.store', [$worker, $nick]))
            ->assertRedirect(route('login'));
    }

    public function test_vakman_cannot_make_an_invite(): void
    {
        [$worker, $nick] = $this->makeNickTeam();
        $vakman = User::factory()->vakman($worker->id)->create();

        $this->actingAs($vakman)
            ->post(route('workers.login-invite.store', [$worker, $nick]))
            ->assertForbidden();
    }

    public function test_creates_an_account_and_shows_a_whatsapp_message_with_password(): void
    {
        $planner = User::factory()->create();
        [$worker, $nick] = $this->makeNickTeam();

        $this->actingAs($planner)
            ->from(route('workers.show', $worker))
            ->post(route('workers.login-invite.store', [$worker, $nick]))
            ->assertRedirect(route('workers.show', $worker));

        $invite = session('vakman_login_invite');
        $this->assertIsArray($invite);
        $this->assertFalse($invite['account_existed']);
        $this->assertTrue($invite['password_generated']);
        $this->assertSame($nick->id, $invite['crew_member_id']);
        $this->assertStringContainsString('Hallo Nick,', $invite['message']);
        $this->assertStringContainsString('06-nummer: 06-57925505', $invite['message']);
        $this->assertStringContainsString(route('vakman.login'), $invite['message']);
        $this->assertStringContainsString('https://web.whatsapp.com/send?phone=31657925505&text=', $invite['whatsapp_url']);
        $this->assertMatchesRegularExpression('/Tijdelijk wachtwoord: \S{10}/', $invite['message']);

        preg_match('/Tijdelijk wachtwoord: (\S+)/', $invite['message'], $matches);
        $account = User::query()->where('crew_member_id', $nick->id)->first();
        $this->assertNotNull($account);
        $this->assertTrue($account->isVakman());
        $this->assertSame('31657925505@telefoon.niconvloeren.nl', $account->email);
        $this->assertTrue(Hash::check($matches[1], $account->password));
        $this->assertNotSame($matches[1], $account->getRawOriginal('password'));

        $this->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('Open WhatsApp')
            ->assertSee('Bericht kopiëren')
            ->assertSee('Hallo Nick,', false)
            ->assertSee($matches[1])
            ->assertDontSee('Nieuw tijdelijk wachtwoord maken');
    }

    public function test_existing_account_keeps_its_password_and_offers_a_reset(): void
    {
        $planner = User::factory()->create();
        [$worker, $nick] = $this->makeNickTeam();
        $account = User::factory()->vakman($worker->id)->create([
            'name' => 'Nick Seine',
            'crew_member_id' => $nick->id,
        ]);

        $this->actingAs($planner)
            ->post(route('workers.login-invite.store', [$worker, $nick]))
            ->assertRedirect(route('workers.show', $worker));

        $invite = session('vakman_login_invite');
        $this->assertTrue($invite['account_existed']);
        $this->assertFalse($invite['password_generated']);
        $this->assertStringNotContainsString('Tijdelijk wachtwoord:', $invite['message']);
        $this->assertTrue(Hash::check('password', $account->fresh()->password));

        $this->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('Nieuw tijdelijk wachtwoord maken')
            ->assertDontSee('Tijdelijk wachtwoord:');
    }

    public function test_reset_creates_a_new_temporary_password(): void
    {
        $planner = User::factory()->create();
        [$worker, $nick] = $this->makeNickTeam();
        $account = User::factory()->vakman($worker->id)->create([
            'crew_member_id' => $nick->id,
        ]);

        $this->actingAs($planner)
            ->from(route('workers.show', $worker))
            ->post(route('workers.login-invite.reset', [$worker, $nick]))
            ->assertRedirect(route('workers.show', $worker));

        $invite = session('vakman_login_invite');
        $this->assertTrue($invite['password_generated']);
        preg_match('/Tijdelijk wachtwoord: (\S+)/', $invite['message'], $matches);
        $this->assertNotSame('password', $matches[1]);
        $this->assertTrue(Hash::check($matches[1], $account->fresh()->password));
        $this->assertFalse(Hash::check('password', $account->fresh()->password));
    }

    public function test_foreign_number_opens_whatsapp_in_international_format(): void
    {
        $planner = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Team Arek',
            'employment_type' => 'eigen',
            'active' => true,
            'people_count' => 1,
            'crew_members' => [
                ['name' => 'Arek Gorzynski', 'phone' => '0048-690668857'],
            ],
        ]);
        $arek = $worker->crewPeople()->first();
        $this->assertNotNull($arek);

        $this->actingAs($planner)
            ->post(route('workers.login-invite.store', [$worker, $arek]))
            ->assertRedirect(route('workers.show', $worker));

        $invite = session('vakman_login_invite');
        $this->assertStringContainsString('Hallo Arek,', $invite['message']);
        $this->assertStringContainsString('Telefoonnummer: 0048-690668857', $invite['message']);
        $this->assertStringContainsString('https://web.whatsapp.com/send?phone=48690668857&text=', $invite['whatsapp_url']);
    }

    public function test_escapes_dangerous_names_in_the_invite_panel(): void
    {
        $planner = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Team',
            'employment_type' => 'eigen',
            'active' => true,
            'people_count' => 1,
            'crew_members' => [
                ['name' => '<script>alert(1)</script>', 'phone' => '0612345678'],
            ],
        ]);
        $member = $worker->crewPeople()->first();
        $this->assertNotNull($member);

        $this->actingAs($planner)
            ->post(route('workers.login-invite.store', [$worker, $member]));

        $this->get(route('workers.show', $worker))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_unknown_crew_member_is_not_found(): void
    {
        $planner = User::factory()->create();
        [$worker] = $this->makeNickTeam();
        $other = Worker::query()->create([
            'name' => 'Ander team',
            'employment_type' => 'eigen',
            'active' => true,
            'people_count' => 1,
            'crew_members' => [
                ['name' => 'Peter', 'phone' => '0611111111'],
            ],
        ]);
        $peter = $other->crewPeople()->first();
        $this->assertNotNull($peter);

        $this->actingAs($planner)
            ->post(route('workers.login-invite.store', [$worker, $peter]))
            ->assertNotFound();
    }

    public function test_missing_phone_is_rejected(): void
    {
        $planner = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Team',
            'employment_type' => 'eigen',
            'active' => true,
            'people_count' => 1,
            'crew_members' => [
                ['name' => 'Nick', 'phone' => ''],
            ],
        ]);
        $member = $worker->crewPeople()->first();
        $this->assertNotNull($member);

        $this->actingAs($planner)
            ->from(route('workers.show', $worker))
            ->post(route('workers.login-invite.store', [$worker, $member]))
            ->assertRedirect(route('workers.show', $worker))
            ->assertSessionHasErrors(['invite' => 'Geen telefoonnummer ingevuld']);
    }

    public function test_office_person_does_not_get_a_vakman_invite(): void
    {
        $planner = User::factory()->create(['name' => 'Marie']);
        User::factory()->admin()->create(['name' => 'Eric Wesselink']);
        $worker = Worker::query()->create([
            'name' => 'Eric Wesselink (Projectleider/Uitvoerder)',
            'employment_type' => 'eigen',
            'active' => true,
            'people_count' => 1,
            'crew_members' => [
                ['name' => 'Eric Wesselink', 'phone' => '06 12345678'],
            ],
        ]);
        $member = $worker->crewPeople()->first();
        $this->assertNotNull($member);

        $html = $this->actingAs($planner)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('form="vakman-login-invite-'.$member->id.'"', $html);

        $this->actingAs($planner)
            ->from(route('workers.show', $worker))
            ->post(route('workers.login-invite.store', [$worker, $member]))
            ->assertRedirect(route('workers.show', $worker))
            ->assertSessionHasErrors(['invite' => 'Deze persoon heeft al een kantoorinlog. Geen aparte vakman-inlog nodig.']);

        $this->assertDatabaseMissing('users', ['crew_member_id' => $member->id]);
    }

    /**
     * @return array{0: Worker, 1: CrewMember}
     */
    private function makeNickTeam(): array
    {
        $worker = Worker::query()->create([
            'name' => 'Team Nick',
            'employment_type' => 'eigen',
            'active' => true,
            'people_count' => 1,
            'crew_members' => [
                ['name' => 'Nick Seine', 'phone' => '06-57925505'],
            ],
        ]);
        $nick = $worker->crewPeople()->first();
        $this->assertNotNull($nick);

        return [$worker, $nick];
    }

    /**
     * @return array{0: Worker, 1: CrewMember, 2: CrewMember}
     */
    private function makeTeamWithNickAndPeter(): array
    {
        $worker = Worker::query()->create([
            'name' => 'Team',
            'employment_type' => 'eigen',
            'active' => true,
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Nick Seine', 'phone' => '06-57925505'],
                ['name' => 'Peter', 'phone' => ''],
            ],
        ]);
        $people = $worker->crewPeople()->orderBy('sort_order')->get();
        $this->assertCount(2, $people);

        return [$worker, $people[0], $people[1]];
    }
}
