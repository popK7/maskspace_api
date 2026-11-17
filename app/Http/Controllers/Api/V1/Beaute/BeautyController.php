<?php

namespace App\Http\Controllers\Api\V1\Beaute;

use App\Enums\BeautyStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BeautyRequestResource;
use App\Models\BeautyRequest;
use App\Services\Beauty\BeautyService;
use App\Services\Dispatch\LocationPing;
use App\Services\Reviews\ProviderReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BeautyController extends Controller
{
    public function __construct(private readonly BeautyService $beauty) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $specialties = $this->beauty->specialtiesOf($user);
        $open = $user->hasCompletedProfile() && $user->is_available && $user->is_visible
            ? $this->beauty->availableFor($user, 200)->count()
            : 0;

        return response()->json(['data' => [
            'is_available' => (bool) $user->is_available,
            'specialties' => $specialties,
            'open_requests' => $open,
            'assigned' => BeautyRequest::where('pro_id', $user->id)->where('status', BeautyStatus::Assigned)->count(),
            ...ProviderReviews::summaryFor(BeautyRequest::class, 'pro_id', $user->id),
        ]]);
    }

    public function available(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        if (! $user->hasCompletedProfile() || ! $user->is_available || ! $user->is_visible) {
            return BeautyRequestResource::collection(collect());
        }

        return BeautyRequestResource::collection($this->beauty->availableFor($user))
            ->additional(['meta' => ['location_required' => ! $user->hasFreshLocation()]]);
    }

    /** POST /beaute/location { latitude, longitude } — position du pro en service. */
    public function location(Request $request, LocationPing $ping): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $ping->record($request->user(), (float) $data['latitude'], (float) $data['longitude']);

        return response()->json(['data' => ['recorded' => true]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = BeautyRequest::with(['client', 'offers.pro'])
            ->where('pro_id', $request->user()->id)
            ->latest('id')
            ->paginate(30);

        return BeautyRequestResource::collection($rows);
    }

    public function offer(Request $request, BeautyRequest $beautyRequest): BeautyRequestResource
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:8', 'max:500']]);
        $this->beauty->offer($request->user(), $beautyRequest, $data['message']);

        return new BeautyRequestResource($beautyRequest->fresh(['client', 'offers.pro']));
    }

    public function complete(Request $request, BeautyRequest $beautyRequest): BeautyRequestResource
    {
        return new BeautyRequestResource($this->beauty->complete($request->user(), $beautyRequest));
    }

    public function cancel(Request $request, BeautyRequest $beautyRequest): BeautyRequestResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new BeautyRequestResource($this->beauty->cancel($request->user(), $beautyRequest, $data['reason'] ?? null));
    }
}
