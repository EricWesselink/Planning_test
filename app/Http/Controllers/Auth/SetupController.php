<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SetupController extends Controller
{
    public function create(): Response
    {
        return response()
            ->view('auth.setup')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'Vul een naam in.',
            'email.required' => 'Vul een e-mailadres in.',
            'email.email' => 'Vul een geldig e-mailadres in.',
            'email.unique' => 'Dit e-mailadres is al in gebruik.',
            'password.required' => 'Vul een wachtwoord in.',
            'password.min' => 'Het wachtwoord moet minstens 8 tekens zijn.',
            'password.confirmed' => 'De wachtwoorden komen niet overeen.',
        ]);

        try {
            $user = Cache::lock('first-run-setup', 10)->block(5, function () use ($data): ?User {
                return DB::transaction(function () use ($data): ?User {
                    if (User::query()->lockForUpdate()->exists()) {
                        return null;
                    }

                    return User::query()->create([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'password' => $data['password'],
                        'role' => UserRole::Admin,
                        'active' => true,
                        'can_access_all_projects' => true,
                        'email_verified_at' => now(),
                    ]);
                });
            });
        } catch (LockTimeoutException) {
            return back()
                ->withErrors([
                    'email' => 'De setup is tijdelijk bezet. Probeer het opnieuw.',
                ])
                ->onlyInput('name', 'email');
        }

        if ($user === null) {
            return redirect()->route('login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
