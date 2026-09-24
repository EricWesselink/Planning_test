<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\NewPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AccountPasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.password');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [...NewPassword::rules(), 'different:current_password'],
        ], [
            'current_password.required' => 'Vul je huidige wachtwoord in.',
            'current_password.current_password' => 'Het huidige wachtwoord is onjuist.',
            ...NewPassword::messages(),
            'password.different' => 'Kies een ander wachtwoord dan je huidige.',
        ]);

        $user = $request->user();
        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();

        Auth::logoutOtherDevices($validated['password']);
        $request->session()->regenerate();

        return redirect()
            ->route('account.password.edit')
            ->with('status', 'Wachtwoord is gewijzigd.');
    }
}
