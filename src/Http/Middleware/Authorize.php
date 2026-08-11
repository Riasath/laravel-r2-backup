<?php

namespace Riasath\R2Backup\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the optional Gate ability named by config('r2-backup.routes.gate').
 *
 * Without a configured ability this is a no-op and the route's own middleware
 * (auth, by default) is the only guard — which is why the config ships with an
 * auth middleware and the README pushes hard on setting a gate.
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        $ability = config('r2-backup.routes.gate');

        if ($ability && Gate::denies($ability)) {
            abort(403, 'You are not allowed to manage backups.');
        }

        return $next($request);
    }
}
