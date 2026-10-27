<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage : ->middleware('role:driver,livreur')
 * Le role est lu sur l'utilisateur charge en base (pas dans le JWT) : un changement de role est immediat.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->hasActiveRole(...$roles)) {
            throw ApiException::forbiddenRole();
        }

        return $next($request);
    }
}
