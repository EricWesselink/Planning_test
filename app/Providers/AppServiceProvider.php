<?php

namespace App\Providers;

use App\Contracts\SnagNotifier;
use App\Models\User;
use App\Services\MailSnagNotifier;
use Carbon\Carbon;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SnagNotifier::class, MailSnagNotifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale('nl');

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower($request->string('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('setup', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('public-snag', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('voucher-mail', function (Request $request) {
            return Limit::perMinute(6)->by(($request->user()?->id ?: $request->ip()).'|'.$request->ip());
        });

        Gate::define('manage-planning', fn (User $user) => $user->canManagePlanning());
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
        Gate::define('manage-workers', fn (User $user) => $user->canManageWorkers());
    }
}
