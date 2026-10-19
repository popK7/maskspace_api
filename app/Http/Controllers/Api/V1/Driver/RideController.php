<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RideResource;
use App\Models\Ride;
use App\Services\Dispatch\RideService;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RideController extends Controller
{
    public function __construct(private readonly RideService $rides) {}

    public function available(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->is_available) {
            return response()->json(['data' => [], 'meta' => ['is_available' => false]]);
        }

        $radius = (float) config('job.radius_km', 15);

        $rows = Ride::with('passenger')
            ->where('status', RideStatus::Searching)
            ->where(function ($q) use ($user) {
                $q->whereNull('offered_driver_id')
                    ->orWhere('offered_driver_id', $user->id)
                    ->orWhereNull('offer_expires_at')
                    ->orWhere('offer_expires_at', '<=', now());
            })
            ->latest('id')->limit(50)->get()
            ->map(function (Ride $ride) use ($user, $radius) {
                $reserved = $ride->hasActiveOffer() && $ride->offered_driver_id === $user->id;
                $km = $user->hasLocation()
                    ? round(Geo::km($user->latitude, $user->longitude, $ride->pickup_latitude, $ride->pickup_longitude), 2)
                    : null;
                // Un appel direct doit toujours arriver au chauffeur vise, meme hors rayon.
                if (! $reserved && $km !== null && $km > $radius) {
                    return null;
                }

                return [
                    'id' => $ride->id,
                    'job_id' => $ride->job_id,
                    'transfer_id' => $ride->transfer_id,
                    'passenger' => ['name' => $ride->passenger?->name, 'role' => $ride->passenger?->role->value],
                    'pickup_neighborhood' => $this->short($ride->pickup_address),
                    'dropoff_neighborhood' => $this->short($ride->dropoff_address),
                    'distance_km' => $ride->distance_km,
                    'price_cents' => $ride->price_cents,
                    'eta_minutes' => $ride->eta_minutes,
                    'distance_to_pickup_km' => $km,
                    'reserved_for_me' => $reserved,
                    'offer_expires_at' => $reserved ? $ride->offer_expires_at?->toIso8601String() : null,
                    'created_at' => $ride->created_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->sortBy(fn ($a) => $a['distance_to_pickup_km'] ?? INF)
            ->values();

        return response()->json(['data' => $rows, 'meta' => ['is_available' => true]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $rides = Ride::with(['passenger', 'job'])
            ->where('driver_id', $request->user()->id)
            ->latest('id')
            ->paginate(min(50, (int) $request->integer('per_page', 20)));

        return RideResource::collection($rides);
    }

    public function show(Request $request, Ride $ride): RideResource
    {
        abort_unless($ride->driver_id === $request->user()->id, 404);

        return new RideResource($ride->load(['passenger', 'driver.profile', 'job']));
    }

    public function accept(Request $request, Ride $ride): RideResource
    {
        return new RideResource($this->rides->accept($request->user(), $ride));
    }

    public function decline(Request $request, Ride $ride): RideResource
    {
        return new RideResource($this->rides->decline($request->user(), $ride));
    }

    public function pickup(Request $request, Ride $ride): RideResource
    {
        abort_unless($ride->driver_id === $request->user()->id, 404);

        return new RideResource($this->rides->pickup($request->user(), $ride));
    }

    public function complete(Request $request, Ride $ride): RideResource
    {
        abort_unless($ride->driver_id === $request->user()->id, 404);

        return new RideResource($this->rides->complete($request->user(), $ride));
    }

    public function cancel(Request $request, Ride $ride): RideResource
    {
        abort_unless($ride->driver_id === $request->user()->id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new RideResource($this->rides->cancel($request->user(), $ride, $data['reason'] ?? null));
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = [now()->startOfDay(), now()->endOfDay()];
        $done = Ride::where('driver_id', $user->id)->where('status', RideStatus::Completed);
        $todayCount = (clone $done)->whereBetween('completed_at', $today)->count();
        $todayCents = (clone $done)->whereBetween('completed_at', $today)->sum('price_cents');
        $lifetime = (clone $done)->count();
        $active = Ride::where('driver_id', $user->id)->whereIn('status', RideStatus::active())->count();

        return response()->json(['data' => [
            'is_available' => (bool) $user->is_available,
            'today_rides' => $todayCount,
            'today_earnings_cents' => (int) $todayCents,
            'lifetime_rides' => $lifetime,
            'active_count' => $active,
        ]]);
    }

    private function short(string $address): string
    {
        $parts = array_map('trim', explode(',', $address));

        return $parts[count($parts) > 1 ? 1 : 0] ?: $address;
    }
}
