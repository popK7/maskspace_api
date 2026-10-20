<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Exemple de ressource reservee au role `admin` : liste et moderation des comptes.
 */
class UserController extends Controller
{
    /** GET /admin/users?role=&status=&search=&per_page= — liste paginee. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'visibility' => ['nullable', 'string', Rule::in(['visible', 'hidden', 'requested'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $users = User::query()
            ->with('profile')
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['visibility'] ?? null, function ($q, $visibility) {
                if ($visibility === 'requested') {
                    $q->where('is_visible', false)->whereNotNull('activation_requested_at');
                } elseif ($visibility === 'hidden') {
                    $q->where('is_visible', false);
                } else {
                    $q->where('is_visible', true);
                }
            })
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return UserResource::collection($users);
    }

    /** PATCH /admin/users/{user}/status — suspend / reactive un compte. */
    public function updateStatus(Request $request, User $user, TokenService $tokens): UserResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
        ]);

        abort_if(
            $user->is($request->user()) || $user->hasRole(UserRole::Admin),
            403,
            'Impossible de modifier le statut de ce compte.',
        );

        $user->forceFill(['status' => $data['status']])->save();

        if ($user->status === UserStatus::Suspended) {
            $tokens->revokeAllFor($user);
        }

        return new UserResource($user->load('profile'));
    }

    /** PATCH /admin/users/{user}/visibility — rend le compte visible, ou le retire. */
    public function updateVisibility(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'is_visible' => ['required', 'boolean'],
        ]);

        abort_if(
            $user->is($request->user()) || $user->hasRole(UserRole::Admin),
            403,
            'Impossible de modifier la visibilité de ce compte.',
        );

        $user->forceFill([
            'is_visible' => (bool) $data['is_visible'],
            'activation_requested_at' => $data['is_visible'] ? $user->activation_requested_at : null,
        ])->save();

        return new UserResource($user->load('profile'));
    }
}
