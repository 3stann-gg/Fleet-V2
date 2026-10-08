<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FleetModuleAction
{
    public function handle(
        Request $request,
        Closure $next,
        string $module,
        ?string $action = null
    ): Response {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $allowed = $action === null
            ? $user->hasPermission($module)
            : $user->canModuleAction($module, $action);

        if (!$allowed) {
            abort(
                403,
                'You do not have permission to perform this action.'
            );
        }

        return $next($request);
    }
}
