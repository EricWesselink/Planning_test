<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\CrewMember;
use App\Models\User;
use App\Models\Worker;
use App\Support\DutchMobileNumber;
use Illuminate\Support\Str;

class VakmanUserResolver
{
    public function find(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (str_contains($identifier, '@')) {
            return $this->findByEmail($identifier);
        }

        return $this->findByMobile($identifier);
    }

    private function findByEmail(string $email): ?User
    {
        $email = Str::lower($email);

        $user = User::query()
            ->where('role', UserRole::Vakman)
            ->whereRaw('lower(email) = ?', [$email])
            ->orderBy('id')
            ->first();
        if ($user !== null) {
            return $user;
        }

        $worker = Worker::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->orderBy('id')
            ->first();

        return $worker === null ? null : $this->teamUser($worker);
    }

    private function findByMobile(string $input): ?User
    {
        $key = DutchMobileNumber::normalize($input);
        if ($key === null) {
            return null;
        }

        $crewMembers = CrewMember::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->with(['user', 'worker.user', 'worker.users'])
            ->orderBy('id')
            ->get();

        foreach ($crewMembers as $member) {
            if (DutchMobileNumber::normalize($member->phone) !== $key) {
                continue;
            }

            if ($member->user?->isVakman()) {
                return $member->user;
            }

            $team = $member->worker === null ? null : $this->teamUser($member->worker);
            if ($team !== null) {
                return $team;
            }
        }

        $workers = Worker::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->with(['user', 'users'])
            ->orderBy('id')
            ->get();

        foreach ($workers as $worker) {
            if (DutchMobileNumber::normalize($worker->phone) !== $key) {
                continue;
            }

            $team = $this->teamUser($worker);
            if ($team !== null) {
                return $team;
            }
        }

        return null;
    }

    private function teamUser(Worker $worker): ?User
    {
        $lead = $worker->relationLoaded('user')
            ? $worker->user
            : $worker->user()->where('role', UserRole::Vakman)->first();
        if ($lead?->isVakman()) {
            return $lead;
        }

        $users = $worker->relationLoaded('users')
            ? $worker->users
            : $worker->users()->orderBy('id')->get();

        return $users->first(fn (User $user): bool => $user->isVakman());
    }
}
