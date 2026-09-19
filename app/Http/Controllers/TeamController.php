<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('workers.index');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canManageTeams() ?? false, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        Team::query()->create([
            'name' => $data['name'],
            'active' => true,
        ]);

        return redirect()
            ->route('workers.index')
            ->with('status', 'Ploeg opgeslagen.');
    }
}
