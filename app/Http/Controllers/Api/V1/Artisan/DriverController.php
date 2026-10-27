<?php

namespace App\Http\Controllers\Api\V1\Artisan;

use App\Enums\RideStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ArtisanDriver;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Transporteurs habituels de l'artisan (ceux avec qui il a l'habitude de travailler). */
class DriverController extends Controller
{
    private const MAX_TRUSTED = 20;

    /**
     * GET /artisan/drivers
     * `data` : mes habituels · `recent` : ont déjà fait une course avec moi, pas encore enregistrés.
     */
    public function index(Request $request): JsonResponse
    {
        $artisan = $request->user();

        $saved = ArtisanDriver::with('driver.profile')->where('artisan_id', $artisan->id)->latest('id')->get()
            ->filter(fn (ArtisanDriver $row) => $row->driver !== null);

        $ids = $saved->pluck('driver_id');
        $together = $this->togetherCounts($artisan->id, $ids->all());
        $busy = $this->activeCounts($ids->all());

        $data = $saved->map(fn (ArtisanDriver $row) => $this->payload(
            $row->driver, true, $together[$row->driver_id] ?? 0, $busy[$row->driver_id] ?? 0,
        ))->values();

        $recentIds = Ride::where('passenger_id', $artisan->id)->where('status', RideStatus::Completed)
            ->whereNotNull('driver_id')->whereNotIn('driver_id', $ids)
            ->selectRaw('driver_id, MAX(id) as last_id')->groupBy('driver_id')
            ->orderByDesc('last_id')->limit(5)->pluck('driver_id')->all();

        $recentTogether = $this->togetherCounts($artisan->id, $recentIds);
        $recentBusy = $this->activeCounts($recentIds);
        $recent = User::with('profile')->whereIn('id', $recentIds)->where('status', UserStatus::Active)->get()
            ->sortBy(fn (User $u) => array_search($u->id, $recentIds, true))
            ->map(fn (User $u) => $this->payload($u, false, $recentTogether[$u->id] ?? 0, $recentBusy[$u->id] ?? 0))
            ->values();

        return response()->json(['data' => $data, 'recent' => $recent]);
    }

    /**
     * GET /artisan/drivers/search?q=
     * Par nom, téléphone exact ou e-mail exact. Pas d'annuaire complet.
     */
    public function search(Request $request): JsonResponse
    {
        $artisan = $request->user();
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:3', 'max:100']])['q']);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $digits = preg_replace('/\D+/', '', $q) ?? '';

        $users = User::forRole(UserRole::Driver)->where('status', UserStatus::Active)
            ->where(function ($w) use ($like, $q, $digits) {
                $w->where('name', 'like', $like)->orWhere('email', $q);
                if (strlen($digits) >= 6) {
                    $w->orWhere('phone', $q)->orWhere('phone', $digits)->orWhere('phone', '+'.$digits);
                }
            })
            ->with('profile')->orderBy('name')->limit(10)->get();

        $savedIds = ArtisanDriver::where('artisan_id', $artisan->id)->pluck('driver_id')->all();
        $together = $this->togetherCounts($artisan->id, $users->pluck('id')->all());
        $busy = $this->activeCounts($users->pluck('id')->all());

        return response()->json(['data' => $users->map(fn (User $u) => $this->payload(
            $u, in_array($u->id, $savedIds, true), $together[$u->id] ?? 0, $busy[$u->id] ?? 0, maskPhone: true,
        ))->values()]);
    }

    /** POST /artisan/drivers { driver_id } — idempotent. */
    public function store(Request $request): JsonResponse
    {
        $artisan = $request->user();
        $data = $request->validate(['driver_id' => ['required', 'integer']]);

        $driver = User::forRole(UserRole::Driver)->where('status', UserStatus::Active)->find($data['driver_id']);
        if (! $driver) {
            return response()->json([
                'message' => 'Transporteur introuvable.',
                'errors' => ['driver_id' => ['Transporteur introuvable.']],
            ], 422);
        }

        if (ArtisanDriver::where('artisan_id', $artisan->id)->count() >= self::MAX_TRUSTED
            && ! ArtisanDriver::where('artisan_id', $artisan->id)->where('driver_id', $driver->id)->exists()) {
            return response()->json([
                'message' => 'Vous ne pouvez pas enregistrer plus de '.self::MAX_TRUSTED.' transporteurs habituels.',
                'errors' => ['driver_id' => ['Limite atteinte.']],
            ], 422);
        }

        ArtisanDriver::firstOrCreate(['artisan_id' => $artisan->id, 'driver_id' => $driver->id]);

        $together = $this->togetherCounts($artisan->id, [$driver->id]);
        $busy = $this->activeCounts([$driver->id]);
        $driver->load('profile');

        return response()->json([
            'data' => $this->payload($driver, true, $together[$driver->id] ?? 0, $busy[$driver->id] ?? 0),
        ], 201);
    }

    /** DELETE /artisan/drivers/{driver} — idempotent. */
    public function destroy(Request $request, int $driver): JsonResponse
    {
        ArtisanDriver::where('artisan_id', $request->user()->id)->where('driver_id', $driver)->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function payload(User $u, bool $saved, int $together, int $busy, bool $maskPhone = false): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $maskPhone ? $this->mask($u->phone) : $u->phone,
            'vehicle' => $u->profileForRole(UserRole::Driver)->first()?->vehicle_model,
            'is_available' => (bool) $u->is_available,
            'saved' => $saved,
            'rides_together' => $together,
            'active_rides' => $busy,
        ];
    }

    private function mask(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) > 2 ? '••• •• '.substr($digits, -2) : null;
    }

    /** @param list<int> $ids  @return array<int, int> */
    private function togetherCounts(int $artisanId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Ride::where('passenger_id', $artisanId)->where('status', RideStatus::Completed)->whereIn('driver_id', $ids)
            ->selectRaw('driver_id, COUNT(*) as n')->groupBy('driver_id')->pluck('n', 'driver_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** @param list<int> $ids  @return array<int, int> */
    private function activeCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Ride::whereIn('driver_id', $ids)->whereIn('status', RideStatus::active())
            ->selectRaw('driver_id, COUNT(*) as n')->groupBy('driver_id')->pluck('n', 'driver_id')
            ->map(fn ($n) => (int) $n)->all();
    }
}
