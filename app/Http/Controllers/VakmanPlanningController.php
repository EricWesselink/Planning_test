<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Models\Voucher;
use App\Services\VakmanPlanningService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VakmanPlanningController extends Controller
{
    public function index(Request $request, VakmanPlanningService $planning): View
    {
        $user = $this->vakman($request);

        return view('vakman.planning', [
            'worker' => $user->worker,
            'agenda' => $planning->agenda(
                $user,
                $request->string('view')->toString(),
                $request->query('week'),
                $request->query('month'),
            ),
        ]);
    }

    public function day(Request $request, string $date, VakmanPlanningService $planning): View
    {
        $user = $this->vakman($request);

        return view('vakman.day', [
            'worker' => $user->worker,
            'detail' => $planning->day($user, $this->parseDate($date)),
            'weekUrl' => route('vakman.planning', [
                'view' => 'week',
                'week' => $this->parseDate($date)->startOfWeek(Carbon::MONDAY)->toDateString(),
            ]),
        ]);
    }

    public function werkbon(Request $request, string $date, VakmanPlanningService $planning): View
    {
        $user = $this->vakman($request);
        abort_if($user->worker?->employment_type?->isExternal() ?? false, 403);

        $detail = $planning->day($user, $this->parseDate($date));
        abort_if($detail['jobs'] === [], 404);

        return view('vakman.werkbon', [
            'worker' => $user->worker,
            'detail' => $detail,
        ]);
    }

    public function opdrachtbon(Request $request, string $date, Project $project, VakmanPlanningService $planning): View|RedirectResponse
    {
        $user = $this->vakman($request);
        abort_unless($user->worker?->employment_type?->isExternal() ?? false, 403);
        abort_unless($user->canAccessProject($project), 403);

        $detail = $planning->day($user, $this->parseDate($date));
        $job = collect($detail['jobs'])->first(
            fn (array $row): bool => (int) $row['project']->id === (int) $project->id
        );
        abort_if($job === null, 404);

        $voucher = Voucher::latestOpdracht((int) $user->scheduledWorkerId(), (int) $project->id);
        if ($voucher !== null) {
            return redirect()->route('vouchers.show', $voucher);
        }

        return view('vakman.opdrachtbon', [
            'worker' => $user->worker,
            'detail' => $detail,
            'job' => $job,
            'works' => $planning->pricedWorks($job['assignment']),
        ]);
    }

    private function vakman(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->isVakman(), 403);
        $user->loadMissing('worker');

        return $user;
    }

    private function parseDate(string $date): Carbon
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        $day = Carbon::createFromFormat('Y-m-d', $date);
        abort_unless($day instanceof Carbon && $day->format('Y-m-d') === $date, 404);

        return $day->startOfDay();
    }
}
