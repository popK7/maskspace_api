<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /** POST /auth/email/verify — valide le code recu par e-mail. */
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->auth->verifyEmail($user, $request->validated('code'));

        return response()->json([
            'message' => __('auth.messages.email_verified'),
            'user' => new UserResource($user->fresh('profile')),
        ]);
    }

    /** POST /auth/activation — compatibilité avec les anciennes versions mobiles. */
    public function requestActivation(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->auth->requestActivation($user);

        return response()->json([
            'message' => 'Votre profil est prêt. Vous pouvez maintenant gérer votre disponibilité.',
            'user' => new UserResource($user->fresh('profile')),
        ]);
    }

    /** POST /auth/email/resend — renvoie un code (202). */
    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => __('auth.messages.verification_sent')], 202);
    }
}
