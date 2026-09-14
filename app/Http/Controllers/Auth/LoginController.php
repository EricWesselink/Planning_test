<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function create(): Response
    {
        return response()
            ->view('auth.login', [
                'needsSetup' => ! User::query()->exists(),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'Deze combinatie van e-mail en wachtwoord is onjuist.',
            ])->onlyInput('email');
        }

        $user = Auth::user();
        if (! $user->active) {
            Auth::logout();

            return back()->withErrors([
                'email' => 'Dit account is niet actief.',
            ]);
        }

        $request->session()->regenerate();

        $home = $user->isVakman()
            ? route('vakman.planning')
            : route('dashboard');

        return redirect()->intended($home);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $loginRoute = $request->user()?->isVakman() ? 'vakman.login' : 'login';

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($loginRoute);
    }
}
