<?php

namespace App\Http\Controllers\Api\V1\Ride;

use App\Http\Controllers\Controller;
use App\Http\Resources\RideResource;
use App\Models\Ride;
use App\Models\RideOffer;
use App\Enums\UserRole;
use App\Services\Dispatch\RideService;
use App\Services\Shop\LivreurStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Course vue par le passager (artisan, plus tard client). */
class RideController extends Controller
{
    public function __construct(
        private readonly RideService $rides,
        private readonly LivreurStats $livreurStats,
    ) {}

    public function store(Request $request): RideResource
    {
        $data = $request->validate([
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_latitude' => ['required', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_address' => ['required', 'string', 'max:255'],
            'dropoff_latitude' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return new RideResource($this->rides->request($request->user(), $data));
    }

    public function offers(Request $request, Ride $ride): \Illuminate\Http\JsonResponse
    {
        $this->authorizePassenger($request, $ride);

        $offers = RideOffer::with('driver.profile')
            ->where('ride_id', $ride->id)
            ->where('status', 'pending')
            ->orderBy('price_cents')
            ->get()
            ->map(function (RideOffer $offer) {
                $driver = $offer->driver;
                $isLivreur = $driver->hasRole(UserRole::Livreur)
                    && ! $driver->hasRole(UserRole::Driver);
                $rating = $isLivreur
                    ? $this->livreurStats->rating($driver)
                    : ['avg' => null, 'count' => 0];

                return [
                    'id' => $offer->id,
                    'driver_id' => $driver->id,
                    'driver_name' => $driver->name,
                    'vehicle' => $driver->profileForRole(
                        $isLivreur ? UserRole::Livreur : UserRole::Driver,
                    )->first()?->vehicle_model,
                    'price_cents' => $offer->price_cents,
                    'eta_minutes' => $offer->eta_minutes,
                    'distance_to_pickup_km' => $offer->distance_to_pickup_km,
                    'rating' => $rating['avg'],
                    'rating_count' => $rating['count'],
                    'created_at' => $offer->created_at?->toIso8601String(),
                ];
            })
            ->values();

        return response()->json(['data' => $offers]);
    }

    public function chooseOffer(Request $request, Ride $ride, RideOffer $offer): RideResource
    {
        $this->authorizePassenger($request, $ride);

        return new RideResource($this->rides->chooseOffer($request->user(), $ride, $offer));
    }

    public function show(Request $request, Ride $ride): RideResource
    {
        $this->authorizePassenger($request, $ride);

        return new RideResource($ride->load(['passenger', 'driver.profile', 'offeredDriver.profile', 'job']));
    }

    public function cancel(Request $request, Ride $ride): RideResource
    {
        $this->authorizePassenger($request, $ride);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new RideResource($this->rides->cancel($request->user(), $ride, $data['reason'] ?? null));
    }

    public function tracking(Request $request, Ride $ride): JsonResponse
    {
        $this->authorizePassenger($request, $ride);
        $ride->load(['driver.profile', 'passenger']);

        return response()->json(['data' => $this->rides->tracking($ride)]);
    }

    private function authorizePassenger(Request $request, Ride $ride): void
    {
        abort_unless($ride->passenger_id === $request->user()->id, 404);
    }
}
