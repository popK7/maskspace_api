<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Collection;

/**
 * Regle commune a tous les services : une nouvelle demande n'est annoncee qu'aux
 * prestataires les plus proches. Ils doivent etre en service, visibles, avoir une
 * position recente et etre dans le rayon ; les plus proches d'abord, avec un plafond.
 */
class NearbyProviders
{
    public function __construct(private readonly PushNotificationService $notifications) {}

    /**
     * @param  (callable(User): bool)|null  $eligible  filtre metier (specialite, corridor...)
     * @return Collection<int, User> prestataires retenus, du plus proche au plus loin
     */
    public function find(
        UserRole $role,
        float $latitude,
        float $longitude,
        ?callable $eligible = null,
        ?float $radiusKm = null,
        ?int $limit = null,
    ): Collection {
        $radiusKm ??= (float) config('job.radius_km', 15);
        $limit ??= max(1, (int) config('job.notify_max', 10));

        return User::query()
            ->with('profile')
            ->forActiveRole($role)
            ->where('status', UserStatus::Active)
            ->where('is_visible', true)
            ->where('is_available', true)
            ->where('location_updated_at', '>=', now()->subSeconds((int) config('job.stale_seconds', 90)))
            ->tap(fn ($q) => Geo::whereWithinBox($q, $latitude, $longitude, $radiusKm))
            ->get()
            ->filter(fn (User $u) => $u->hasFreshLocation())
            ->map(fn (User $u) => [$u, Geo::km($u->latitude, $u->longitude, $latitude, $longitude)])
            ->filter(fn (array $row) => $row[1] <= $radiusKm)
            ->filter(fn (array $row) => $eligible === null || $eligible($row[0]))
            ->sortBy(fn (array $row) => $row[1])
            ->take($limit)
            ->map(fn (array $row) => $row[0])
            ->values();
    }

    /**
     * Previent les prestataires retenus par [find].
     *
     * @param  array<string, mixed>  $data
     * @param  (callable(User): bool)|null  $eligible
     * @return int nombre de prestataires prevenus
     */
    public function notify(
        UserRole $role,
        float $latitude,
        float $longitude,
        string $title,
        string $body,
        array $data,
        ?callable $eligible = null,
        ?float $radiusKm = null,
    ): int {
        $providers = $this->find($role, $latitude, $longitude, $eligible, $radiusKm);
        foreach ($providers as $provider) {
            $this->notifications->sendToUser($provider, $title, $body, $data);
        }

        return $providers->count();
    }
}
