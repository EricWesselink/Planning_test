<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VakmanPasswordController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless($request->user()?->isVakman(), 403);

        return view('vakman.password');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isVakman(), 403);

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], [
            'current_password.required' => 'Vul je huidige wachtwoord in.',
            'current_password.current_password' => 'Het huidige wachtwoord is onjuist.',
            'password.required' => 'Vul een nieuw wachtwoord in.',
            'password.min' => 'Het wachtwoord moet minstens 8 tekens zijn.',
            'password.confirmed' => 'De wachtwoorden komen niet overeen.',
            'password.different' => 'Kies een ander wachtwoord dan je huidige.',
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
        ])->save();

        $request->session()->regenerate();

        return redirect()
            ->route('vakman.password.edit')
            ->with('status', 'Wachtwoord is gewijzigd.');
    }
}
