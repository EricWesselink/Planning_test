<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Vul een e-mailadres in.',
            'email.email' => 'Vul een geldig e-mailadres in.',
        ]);

        $email = strtolower($request->string('email')->toString());
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null && $user->hasDeliverableEmail()) {
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', 'Als er een account met deze gegevens bestaat, ontvang je een e-mail.');
    }
}
