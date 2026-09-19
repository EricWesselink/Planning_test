<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ImpersonateController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('impersonate', $user);
        abort_if($request->session()->has('impersonator_id'), 403);

        $actor = $request->user();
        abort_if($actor === null, 403);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('impersonator_id', $actor->id);
        $request->session()->put('impersonate_id', $user->id);

        Log::info('Account overgenomen', [
            'actor_id' => $actor->id,
            'actor_email' => $actor->email,
            'target_id' => $user->id,
            'target_email' => $user->email,
            'target_name' => $user->name,
        ]);

        return redirect()->to($this->homeUrl($user));
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->get('impersonator_id');
        abort_unless(is_numeric($impersonatorId), 403);

        $actor = User::query()->find((int) $impersonatorId);
        abort_if($actor === null || ! $actor->active, 403);

        $target = $request->user();

        Auth::login($actor);
        $request->session()->forget(['impersonator_id', 'impersonate_id']);
        $request->session()->regenerate();

        Log::info('Account overname beëindigd', [
            'actor_id' => $actor->id,
            'actor_email' => $actor->email,
            'target_id' => $target?->id,
            'target_email' => $target?->email,
            'target_name' => $target?->name,
        ]);

        return redirect()->route('users.index');
    }

    private function homeUrl(User $user): string
    {
        if ($user->isVakman()) {
            return route('vakman.planning');
        }

        return route($user->officeHomeRouteName());
    }
}
