<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * POST /auth/forgot-password — 202 Accepted.
     * Reponse strictement identique que l'e-mail existe ou non (pas d'enumeration de comptes).
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->sendPasswordResetCode($request->validated('email'));

        return response()->json(['message' => __('auth.messages.reset_link_sent')], 202);
    }

    /** POST /auth/reset-password — 200. Ferme aussi toutes les sessions existantes. */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->auth->resetPassword($data['email'], $data['code'], $data['password']);

        return response()->json(['message' => __('auth.messages.password_reset')]);
    }
}
