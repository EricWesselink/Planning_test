<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Services\AccountActivationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class UserActivationController extends Controller
{
    public function store(User $user, AccountActivationService $activations): RedirectResponse
    {
        Gate::authorize('update', $user);

        if (! $user->hasDeliverableEmail()) {
            return back()->with('status', 'Dit account heeft geen e-mailadres. Maak de activatielink via het inlogbericht bij de vakman.');
        }

        $plain = $activations->issue($user);

        $user->notify(new AccountActivationNotification($activations->url($plain)));

        return back()->with('status', 'Activatielink is per e-mail verstuurd.');
    }
}
