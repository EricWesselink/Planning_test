<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\VakmanUserResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class VakmanLoginController extends Controller
{
    public function __construct(private VakmanUserResolver $vakmannen) {}

    public function create(): Response
    {
        return response()
            ->view('auth.vakman-login')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Vul een 06-nummer of e-mailadres in.',
            'password.required' => 'Vul een wachtwoord in.',
        ]);

        $user = $this->vakmannen->find($credentials['login']);
        $password = $user?->password;

        if ($user === null || ! is_string($password) || $password === '' || ! Hash::check($credentials['password'], $password)) {
            return back()->withErrors([
                'login' => 'Deze combinatie van 06-nummer of e-mailadres en wachtwoord is onjuist.',
            ])->onlyInput('login');
        }

        if (! $user->active) {
            return back()->withErrors([
                'login' => 'Dit account is niet actief.',
            ])->onlyInput('login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('vakman.planning'));
    }
}
