<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\CompleteProfileRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /** GET /me */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['profile', 'roles']));
    }

    /** PATCH /me — mise a jour partielle des informations du compte courant. */
    public function update(UpdateProfileRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        DB::transaction(function () use ($user, $data) {
            $user->fill(array_intersect_key($data, array_flip(['name', 'phone'])))->save();

            $profileData = array_intersect_key(
                $data,
                array_flip(['shop_name', 'vehicle_model', 'vehicle_plate', 'specialties', 'housing_zones']),
            );
            if ($profileData !== []) {
                $user->profileForRole($user->role)->updateOrCreate(
                    ['role' => $user->role->value],
                    $profileData,
                );
            }

            $user->refresh()->load(['profile', 'shop']);
            if ($user->role->requiresShopName() && filled($user->profile?->shop_name)) {
                $this->auth->syncShop($user, [
                    'shop_name' => $user->profile->shop_name,
                    'specialties' => $user->profile->specialties ?? [],
                ]);
            }
            if ($user->is_visible && $user->profile_completed_at === null) {
                $user->forceFill(['profile_completed_at' => now()]);
                if ($user->profileCompletionPercent() === 100) {
                    $user->save();
                }
            }
        });

        return new UserResource($user->fresh('profile'));
    }

    /** POST /me/profile — rôle et informations exigées, avant l'activation. */
    public function complete(CompleteProfileRequest $request): UserResource
    {
        $user = $this->auth->completeProfile($request->user(), $request->validated());

        return new UserResource($user);
    }

    /** PATCH /me/role — sélectionne un espace déjà complété. */
    public function switchRole(Request $request): UserResource
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(UserRole::selfRegistrableValues())],
        ]);
        $user = $this->auth->switchRole($request->user(), UserRole::from($data['role']));

        return new UserResource($user);
    }

    /** PUT /me/password — change le mot de passe et ferme les AUTRES sessions. */
    public function updatePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->auth->changePassword(
            $user,
            $request->validated('password'),
            Auth::guard('api')->payload()->get('sid'),
        );

        return response()->json(['message' => __('auth.messages.password_changed')]);
    }
}
