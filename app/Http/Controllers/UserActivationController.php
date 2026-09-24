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
        $url = $activations->url($plain);

        if ($user->hasDeliverableEmail() && config('mail.default') !== 'log') {
            $user->notify(new AccountActivationNotification($url));

            return back()
                ->with('status', 'Activatielink is per e-mail verstuurd.')
                ->with('activation_url', $url);
        }

        return back()
            ->with('status', 'Kopieer de activatielink. Op deze computer wordt geen e-mail naar een postvak gestuurd.')
            ->with('activation_url', $url);
    }
}
