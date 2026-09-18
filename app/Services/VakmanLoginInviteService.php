<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\CrewMember;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VakmanLoginInviteService
{
    /**
     * @return array{
     *     account: User,
     *     message: string,
     *     whatsapp_url: string,
     *     account_existed: bool,
     *     password_generated: bool,
     *     crew_member_id: int
     * }
     */
    public function createOrPrepare(CrewMember $member): array
    {
        $account = $this->existingAccount($member);
        if ($account !== null) {
            return $this->payload($member, $account, password: null, accountExisted: true);
        }

        if ($this->officeAccount($member) !== null) {
            throw ValidationException::withMessages([
                'invite' => 'Deze persoon heeft al een kantoorinlog. Geen aparte vakman-inlog nodig.',
            ]);
        }

        $plain = $this->temporaryPassword();
        $account = $this->createAccount($member, $plain);

        return $this->payload($member, $account, password: $plain, accountExisted: false);
    }

    /**
     * @return array{
     *     account: User,
     *     message: string,
     *     whatsapp_url: string,
     *     account_existed: bool,
     *     password_generated: bool,
     *     crew_member_id: int
     * }
     */
    public function resetPassword(CrewMember $member): array
    {
        $account = $this->existingAccount($member);
        if ($account === null) {
            throw ValidationException::withMessages([
                'invite' => 'Er is nog geen inlogaccount voor deze vakman.',
            ]);
        }

        $plain = $this->temporaryPassword();
        $account->forceFill(['password' => $plain])->save();

        return $this->payload($member, $account->fresh(), password: $plain, accountExisted: true);
    }

    public function existingAccount(CrewMember $member): ?User
    {
        $user = $member->relationLoaded('user')
            ? $member->user
            : $member->user()->first();

        return $user?->isVakman() ? $user : null;
    }

    private function officeAccount(CrewMember $member): ?User
    {
        $worker = $member->relationLoaded('worker')
            ? $member->worker
            : $member->worker()->first();

        return $worker?->officeLoginForPerson($member->displayName());
    }

    /**
     * @return array{
     *     account: User,
     *     message: string,
     *     whatsapp_url: string,
     *     account_existed: bool,
     *     password_generated: bool,
     *     crew_member_id: int
     * }
     */
    private function payload(CrewMember $member, User $account, ?string $password, bool $accountExisted): array
    {
        [$whatsAppId, $displayPhone] = $this->phoneParts($member);
        $message = $this->message($member, $displayPhone, $password);
        $whatsAppUrl = 'https://wa.me/'.$whatsAppId.'?text='.rawurlencode($message);

        return [
            'account' => $account,
            'message' => $message,
            'whatsapp_url' => $whatsAppUrl,
            'account_existed' => $accountExisted,
            'password_generated' => $password !== null,
            'crew_member_id' => (int) $member->id,
        ];
    }

    private function createAccount(CrewMember $member, string $password): User
    {
        $this->phoneParts($member);
        $email = $this->loginEmail($member);

        $taken = User::query()->where('email', $email)->first();
        if ($taken !== null) {
            throw ValidationException::withMessages([
                'invite' => 'Dit telefoonnummer is al gekoppeld aan een andere inlog.',
            ]);
        }

        return User::query()->create([
            'name' => $member->label(),
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Vakman,
            'active' => true,
            'can_access_all_projects' => false,
            'worker_id' => $member->worker_id,
            'crew_member_id' => $member->id,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function phoneParts(CrewMember $member): array
    {
        $whatsAppId = PhoneNumber::whatsAppId($member->phone);
        if ($whatsAppId === null) {
            throw ValidationException::withMessages([
                'invite' => 'Geen telefoonnummer ingevuld',
            ]);
        }

        return [$whatsAppId, PhoneNumber::display($member->phone)];
    }

    private function loginEmail(CrewMember $member): string
    {
        $whatsAppId = PhoneNumber::whatsAppId($member->phone);
        if ($whatsAppId === null) {
            throw ValidationException::withMessages([
                'invite' => 'Geen telefoonnummer ingevuld',
            ]);
        }

        return $whatsAppId.'@telefoon.niconvloeren.nl';
    }

    private function message(CrewMember $member, string $displayPhone, ?string $password): string
    {
        $greeting = $this->greetingName($member);
        $company = (string) config('company.name');
        $loginUrl = route('vakman.login');
        $phoneLabel = str_starts_with($displayPhone, '06') ? '06-nummer' : 'Telefoonnummer';

        $lines = [
            'Hallo '.$greeting.',',
            '',
            'Je kunt nu inloggen op je planning bij '.$company.'.',
            '',
            'Inloggen: '.$loginUrl,
            $phoneLabel.': '.$displayPhone,
        ];

        if ($password !== null) {
            $lines[] = 'Tijdelijk wachtwoord: '.$password;
        }

        $lines[] = '';
        $lines[] = 'Na het inloggen kun je zelf je wachtwoord wijzigen.';
        $lines[] = '';
        $lines[] = 'Lukt het niet, stuur me even een appje.';

        return implode("\n", $lines);
    }

    private function greetingName(CrewMember $member): string
    {
        $name = trim($member->label());
        if ($name === '') {
            return 'vakman';
        }

        return explode(' ', $name, 2)[0];
    }

    private function temporaryPassword(): string
    {
        return Str::password(10, symbols: false);
    }
}
