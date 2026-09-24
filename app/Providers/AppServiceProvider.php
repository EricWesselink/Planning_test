<?php

namespace App\Providers;

use App\Contracts\SnagNotifier;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\MailSnagNotifier;
use App\Support\AssignmentCoverage;
use Carbon\Carbon;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SnagNotifier::class, MailSnagNotifier::class);
        $this->app->scoped(AssignmentCoverage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale('nl');

        $this->app->terminating(function (AssignmentCoverage $coverage): void {
            $coverage->flush();
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower($request->string('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('vakman-login', function (Request $request) {
            $login = Str::transliterate(Str::lower($request->string('login')));

            return Limit::perMinute(5)->by($login.'|'.$request->ip());
        });

        RateLimiter::for('setup', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('public-snag', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('public-snag-write', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip().'|'.$request->route('token'));
        });

        RateLimiter::for('voucher-mail', function (Request $request) {
            return Limit::perMinute(6)->by(($request->user()?->id ?: $request->ip()).'|'.$request->ip());
        });

        RateLimiter::for('vakman-login-invite', function (Request $request) {
            return Limit::perMinute(10)->by(($request->user()?->id ?: $request->ip()).'|'.$request->ip());
        });

        RateLimiter::for('vakman-password', function (Request $request) {
            return Limit::perMinute(10)->by(($request->user()?->id ?: $request->ip()).'|'.$request->ip());
        });

        RateLimiter::for('account-password', function (Request $request) {
            return Limit::perMinute(10)->by(($request->user()?->id ?: $request->ip()).'|'.$request->ip());
        });

        RateLimiter::for('activation', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('activation-resend', function (Request $request) {
            return Limit::perMinute(10)->by(($request->user()?->id ?: $request->ip()).'|'.$request->ip());
        });

        RateLimiter::for('password-email', function (Request $request) {
            $email = Str::transliterate(Str::lower($request->string('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        Gate::define('manage-planning', fn (User $user) => $user->canManagePlanning());
        Gate::define('view-planning', fn (User $user) => $user->canViewPlanning());
        Gate::define('view-dashboard', fn (User $user) => $user->canViewDashboard());
        Gate::define('view-production', fn (User $user) => $user->canViewProduction());
        Gate::define('view-personnel', fn (User $user) => $user->canViewPersonnelWeek());
        Gate::define('planning-assign', fn (User $user) => $user->canAssignPlanning());
        Gate::define('planning-drag', fn (User $user) => $user->canDragPlanning());
        Gate::define('planning-hours', fn (User $user) => $user->canAdjustPlanningHours());
        Gate::define('manage-catalog', fn (User $user) => $user->canManageCatalog());
        Gate::define('enter-progress', function (User $user) {
            return $user->canEnterProgress()
                ? Response::allow()
                : Response::deny('Je mag deze voortgang niet opslaan.');
        });
        Gate::define('approve-progress', function (User $user) {
            return $user->canApproveProgress()
                ? Response::allow()
                : Response::deny('Je mag dit werk niet definitief maken.');
        });
        Gate::define('review-hours', function (User $user) {
            return $user->canReviewHours()
                ? Response::allow()
                : Response::deny('Je mag uren niet beoordelen.');
        });
        Gate::define('view-hours', fn (User $user) => $user->canViewHours());
        Gate::define('manage-workers', fn (User $user) => $user->canManageWorkers());

        View::composer('layouts.app', function ($view): void {
            $user = auth()->user();
            $pendingLeaveRequestCount = 0;
            if ($user?->canViewLeaveRequests()) {
                $pendingLeaveRequestCount = LeaveRequest::query()->pending()->count();
            }

            $view->with('pendingLeaveRequestCount', $pendingLeaveRequestCount);
        });
    }
}
