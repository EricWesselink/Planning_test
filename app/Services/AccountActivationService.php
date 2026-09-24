<?php

namespace App\Services;

use App\Models\AccountActivation;
use App\Models\User;
use Illuminate\Support\Str;

class AccountActivationService
{
    public const EXPIRES_HOURS = 24;

    public function issue(User $user): string
    {
        AccountActivation::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $plain = Str::random(64);

        AccountActivation::query()->create([
            'user_id' => $user->id,
            'token_hash' => $this->hash($plain),
            'expires_at' => now()->addHours(self::EXPIRES_HOURS),
        ]);

        return $plain;
    }

    public function findUsable(string $plainToken): ?AccountActivation
    {
        $activation = AccountActivation::query()
            ->where('token_hash', $this->hash($plainToken))
            ->first();

        if ($activation === null || ! $activation->isUsable()) {
            return null;
        }

        return $activation;
    }

    public function url(string $plainToken): string
    {
        return route('activation.show', ['token' => $plainToken]);
    }

    public function consume(AccountActivation $activation, string $password): User
    {
        $user = $activation->user()->firstOrFail();

        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
            'activated_at' => now(),
        ])->save();

        $activation->forceFill(['used_at' => now()])->save();

        AccountActivation::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        return $user;
    }

    public function placeholderPassword(): string
    {
        return Str::password(40);
    }

    private function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
