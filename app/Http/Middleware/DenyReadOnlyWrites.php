<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DenyReadOnlyWrites
{
    /**
     * @var list<string>
     */
    private const EXEMPT_ROUTES = [
        'logout',
        'vakman.password.update',
        'projects.areas.details',
        'users.impersonate.stop',
        'planning.weekplanning.email',
        'planning.personnel-week.email',
        'work-tickets.email',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $request->isMethodSafe() || ! $user->isReadOnlyOfficeUser()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        if (is_string($routeName) && in_array($routeName, self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        abort(403);
    }
}
