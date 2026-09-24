<?php

namespace Tests\Feature;

use App\Mail\WorkerPlanningInviteMail;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerPlanningInviteMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_mentions_vakkennis_team_size_and_temporary_password(): void
    {
        $mail = $this->makeMail();

        $this->assertSame('Uitnodiging planning · Team Wespro', $mail->envelope()->subject);
        $mail->assertSeeInHtml('Team Wespro');
        $mail->assertSeeInHtml('ZZP · 2 personen');
        $mail->assertSeeInHtml('Vakkennis: PVC');
        $mail->assertSeeInHtml('wespro@niconvloeren.nl');
        $mail->assertSeeInHtml('/activeren/');
        $mail->assertDontSeeInHtml('Tijdelijk wachtwoord');
        $mail->assertDontSeeInText('Tijdelijk wachtwoord');
        $mail->assertSeeInText('Log in om je planning te zien.');
        $mail->assertSeeInText('/activeren/');
    }

    public function test_escapes_dangerous_content_in_the_invite(): void
    {
        $mail = $this->makeMail([
            'name' => 'Team <script>alert(1)</script>',
            'specialty' => '<script>alert(1)</script>',
        ]);

        $mail->assertDontSeeInHtml('<script>alert(1)</script>', false);
        $mail->assertSeeInHtml('<script>alert(1)</script>');
        $mail->assertDontSeeInText('<script>alert(1)</script>');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeMail(array $overrides = []): WorkerPlanningInviteMail
    {
        $worker = Worker::query()->create([
            'name' => $overrides['name'] ?? 'Team Wespro',
            'employment_type' => 'zzp',
            'people_count' => 2,
            'specialty' => $overrides['specialty'] ?? 'PVC',
            'active' => true,
        ]);
        $account = User::factory()->vakman($worker->id)->create([
            'name' => $worker->name,
            'email' => 'wespro@niconvloeren.nl',
        ]);

        return new WorkerPlanningInviteMail($worker, $account, route('activation.show', ['token' => 'activeringtoken123']));
    }
}
