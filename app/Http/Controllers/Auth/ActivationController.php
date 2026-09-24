<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AccountActivationService;
use App\Support\NewPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivationController extends Controller
{
    public function __construct(private AccountActivationService $activations) {}

    public function create(string $token): View
    {
        return view('auth.activate', [
            'token' => $token,
            'usable' => $this->activations->findUsable($token) !== null,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $activation = $this->activations->findUsable($token);
        if ($activation === null) {
            return redirect()
                ->route('activation.show', ['token' => $token])
                ->withErrors(['token' => 'Deze activatielink is ongeldig of verlopen. Vraag een nieuwe link aan.']);
        }

        $validated = $request->validate([
            'password' => NewPassword::rules(),
        ], NewPassword::messages());

        $user = $this->activations->consume($activation, $validated['password']);
        $loginRoute = $user->isVakman() ? 'vakman.login' : 'login';

        return redirect()
            ->route($loginRoute)
            ->with('status', 'Je wachtwoord is ingesteld. Je kunt nu inloggen.');
    }
}
