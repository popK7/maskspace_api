<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            'connection' => ['nullable', 'string', Rule::in(['connected', 'disconnected'])],
            'availability' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $users = User::query()
            ->with(['profile', 'roles'])
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->forRole($role))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(array_key_exists('availability', $filters), function ($q) use ($filters) {
                $q->where('is_available', $filters['availability'])
                    ->whereHas('roles', fn ($roles) => $roles
                        ->whereNotIn('role', [UserRole::Client->value, UserRole::Admin->value])
                        ->whereNotNull('profile_completed_at'));
            })
            ->when($filters['connection'] ?? null, function ($q, $connection) {
                $cutoff = now()->subMinutes(2);
                $connection === 'connected'
                    ? $q->where('last_seen_at', '>=', $cutoff)
                    : $q->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff));
            })
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

        $this->attachLastServiceTimestamps($users->getCollection());

        return AdminUserResource::collection($users);
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

    /**
     * Adds the latest completed service involving each account on the current page.
     *
     * @param  Collection<int, User>  $users
     */
    private function attachLastServiceTimestamps(Collection $users): void
    {
        $userIds = $users->modelKeys();
        if ($userIds === []) {
            return;
        }

        $sources = [
            ['artisan_jobs', 'client_id', 'completed_at'],
            ['artisan_jobs', 'artisan_id', 'completed_at'],
            ['rides', 'passenger_id', 'completed_at'],
            ['rides', 'driver_id', 'completed_at'],
            ['orders', 'customer_id', 'delivered_at'],
            ['orders', 'livreur_id', 'delivered_at'],
            ['money_transfers', 'client_id', 'completed_at'],
            ['money_transfers', 'changeur_id', 'completed_at'],
            ['consultations', 'client_id', 'completed_at'],
            ['consultations', 'medecin_id', 'completed_at'],
            ['beauty_requests', 'client_id', 'completed_at'],
            ['beauty_requests', 'pro_id', 'completed_at'],
            ['housing_requests', 'client_id', 'completed_at'],
            ['housing_requests', 'demarcheur_id', 'completed_at'],
            ['lodging_bookings', 'client_id', 'completed_at'],
            ['lodging_bookings', 'host_id', 'completed_at'],
        ];
        $latestByUser = [];

        foreach ($sources as [$table, $userColumn, $timestampColumn]) {
            $rows = DB::table($table)
                ->select($userColumn.' as user_id')
                ->selectRaw('MAX('.$timestampColumn.') as last_service_at')
                ->whereIn($userColumn, $userIds)
                ->whereNotNull($timestampColumn)
                ->groupBy($userColumn)
                ->get();

            foreach ($rows as $row) {
                $current = $latestByUser[$row->user_id] ?? null;
                if ($current === null || $row->last_service_at > $current) {
                    $latestByUser[$row->user_id] = $row->last_service_at;
                }
            }
        }

        $shopOrders = DB::table('orders')
            ->join('shops', 'shops.id', '=', 'orders.shop_id')
            ->select('shops.owner_id as user_id')
            ->selectRaw('MAX(orders.delivered_at) as last_service_at')
            ->whereIn('shops.owner_id', $userIds)
            ->whereNotNull('orders.delivered_at')
            ->groupBy('shops.owner_id')
            ->get();
        foreach ($shopOrders as $row) {
            $current = $latestByUser[$row->user_id] ?? null;
            if ($current === null || $row->last_service_at > $current) {
                $latestByUser[$row->user_id] = $row->last_service_at;
            }
        }

        $users->each(fn (User $user) => $user->setAttribute(
            'last_service_at',
            $latestByUser[$user->id] ?? null,
        ));
    }
}
