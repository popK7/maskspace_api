<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Laisse passer un compte en attente d'activation, refuse un compte suspendu. */
class EnsureAccountIsNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null && $user->status === UserStatus::Suspended) {
            throw ApiException::accountSuspended();
        }

        return $next($request);
    }
}
