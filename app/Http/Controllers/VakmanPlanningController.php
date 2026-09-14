<?php

namespace App\Http\Controllers;

use App\Services\VakmanPlanningService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VakmanPlanningController extends Controller
{
    public function __invoke(Request $request, VakmanPlanningService $planning): View
    {
        $user = $request->user();
        abort_unless($user?->isVakman(), 403);
        $user->loadMissing('worker');

        $today = now()->startOfDay();

        return view('vakman.planning', [
            'today' => $today,
            'entries' => $planning->forUser($user, $today),
            'worker' => $user->worker,
        ]);
    }
}
