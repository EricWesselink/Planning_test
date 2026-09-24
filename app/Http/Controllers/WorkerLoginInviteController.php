<?php

namespace App\Http\Controllers;

use App\Models\CrewMember;
use App\Models\Worker;
use App\Services\VakmanLoginInviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class WorkerLoginInviteController extends Controller
{
    public function store(Worker $worker, CrewMember $crewMember, VakmanLoginInviteService $invites): RedirectResponse
    {
        Gate::authorize('update', $worker);
        $this->assertMemberOfWorker($worker, $crewMember);

        $invite = $invites->createOrPrepare($crewMember);

        return redirect()
            ->route('workers.show', $worker)
            ->with('vakman_login_invite', $this->sessionPayload($invite))
            ->with('status', $invite['password_generated']
                ? 'Inlog is klaar. Stuur de activatielink via WhatsApp of kopieer het bericht.'
                : 'Er is al een inlog. Het bestaande wachtwoord blijft geheim.');
    }

    public function resetPassword(Worker $worker, CrewMember $crewMember, VakmanLoginInviteService $invites): RedirectResponse
    {
        Gate::authorize('update', $worker);
        $this->assertMemberOfWorker($worker, $crewMember);

        $invite = $invites->resetPassword($crewMember);

        return redirect()
            ->route('workers.show', $worker)
            ->with('vakman_login_invite', $this->sessionPayload($invite))
            ->with('status', 'Nieuwe activatielink is klaar. Het huidige wachtwoord blijft werken tot de link is gebruikt.');
    }

    private function assertMemberOfWorker(Worker $worker, CrewMember $crewMember): void
    {
        abort_unless((int) $crewMember->worker_id === (int) $worker->id, 404);
    }

    /**
     * @param  array{
     *     message: string,
     *     whatsapp_url: string,
     *     account_existed: bool,
     *     password_generated: bool,
     *     crew_member_id: int
     * }  $invite
     * @return array{
     *     message: string,
     *     whatsapp_url: string,
     *     account_existed: bool,
     *     password_generated: bool,
     *     crew_member_id: int
     * }
     */
    private function sessionPayload(array $invite): array
    {
        return [
            'message' => $invite['message'],
            'whatsapp_url' => $invite['whatsapp_url'],
            'account_existed' => $invite['account_existed'],
            'password_generated' => $invite['password_generated'],
            'crew_member_id' => $invite['crew_member_id'],
        ];
    }
}
