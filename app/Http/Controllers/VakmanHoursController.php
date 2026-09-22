<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PersonnelHoursService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VakmanHoursController extends Controller
{
    public function index(Request $request, PersonnelHoursService $hours): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isVakman(), 403);

        $week = $request->query('week');

        return view('vakman.hours', [
            'overview' => $hours->ownWeek($user, is_string($week) ? $week : null),
        ]);
    }
}
