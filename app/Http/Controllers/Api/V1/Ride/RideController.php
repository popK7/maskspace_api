<?php

namespace App\Http\Controllers\Api\V1\Ride;

use App\Http\Controllers\Controller;
use App\Http\Resources\RideResource;
use App\Models\Ride;
use App\Services\Dispatch\RideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Course vue par le passager (artisan, plus tard client). */
class RideController extends Controller
{
    public function __construct(private readonly RideService $rides) {}

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
