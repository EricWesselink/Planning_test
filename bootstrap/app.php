<?php

use App\Http\Middleware\DenyReadOnlyWrites;
use App\Http\Middleware\EnsureFirstRunSetup;
use App\Http\Middleware\EnsureProjectAccess;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (Request $request) {
            $name = $request->route()?->getName();
            if (is_string($name) && str_starts_with($name, 'vakman.')) {
                return route('vakman.login');
            }

            return route('login');
        });
        $middleware->redirectUsersTo(function () {
            $user = auth()->user();

            return $user?->isVakman()
                ? route('vakman.planning')
                : route($user?->officeHomeRouteName() ?? 'dashboard');
        });
        $middleware->web(append: [
            EnsureUserIsActive::class,
        ]);
        $middleware->alias([
            'first-run' => EnsureFirstRunSetup::class,
            'project.access' => EnsureProjectAccess::class,
            'deny-readonly-writes' => DenyReadOnlyWrites::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
