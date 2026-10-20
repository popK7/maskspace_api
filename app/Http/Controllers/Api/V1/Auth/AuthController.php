<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleLoginRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
    ) {}

    /** POST /auth/register — 201 */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->validated(), $request);

        return $this->authResponse($result, 201);
    }

    /** POST /auth/login — 200 */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request,
            $request->validated('device_name'),
        );

        return $this->authResponse($result);
    }

    /** POST /auth/google — connexion ou création via un compte Gmail. */
    public function google(GoogleLoginRequest $request): JsonResponse
    {
        $result = $this->auth->loginWithGoogle(
            $request->validated('id_token'),
            $request,
            $request->validated('device_name'),
        );

        return $this->authResponse($result);
    }

    /** POST /auth/refresh — echange un refresh token contre une nouvelle paire (rotation). */
    public function refresh(RefreshRequest $request): JsonResponse
    {
        $result = $this->tokens->rotate($request->validated('refresh_token'), $request);

        return $this->authResponse($result);
    }

    /** POST /auth/logout — ferme la session courante (refresh tokens + access token courant). */
    public function logout(Request $request): JsonResponse
    {
        $guard = Auth::guard('api');

        if ($sid = $guard->payload()->get('sid')) {
            $this->tokens->revokeFamily($sid);
        }
        $guard->logout(); // met l'access token courant en liste noire

        return response()->json(['message' => __('auth.messages.logged_out')]);
    }

    /** POST /auth/logout-all — ferme toutes les sessions de l'utilisateur (tous appareils). */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->tokens->revokeAllFor($user);
        Auth::guard('api')->logout();

        return response()->json(['message' => __('auth.messages.logged_out_all')]);
    }

    /** @param array{user: User, tokens: array} $result */
    private function authResponse(array $result, int $status = 200): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($result['user']->loadMissing('profile')),
            'tokens' => $result['tokens'],
        ], $status)->withHeaders([
            // Les reponses contenant des tokens ne doivent jamais etre mises en cache.
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }
}
