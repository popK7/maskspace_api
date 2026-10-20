<?php

namespace App\Http\Controllers\Api\V1\Demarcheur;

use App\Enums\HousingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\HousingRequestResource;
use App\Models\HousingRequest;
use App\Services\Housing\HousingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HousingController extends Controller
{
    public function __construct(private readonly HousingService $housing) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $zones = $this->housing->zonesOf($user);
        $open = $user->is_available && $user->is_visible
            ? HousingRequest::where('status', HousingStatus::Searching)->whereIn('zone', $zones)->count()
            : 0;

        return response()->json(['data' => [
            'is_available' => (bool) $user->is_available,
            'zones' => $zones,
            'open_requests' => $open,
            'assigned' => HousingRequest::where('demarcheur_id', $user->id)->where('status', HousingStatus::Assigned)->count(),
        ]]);
    }

    public function available(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        if (! $user->is_available || ! $user->is_visible) {
            return HousingRequestResource::collection(collect());
        }

        $zones = $this->housing->zonesOf($user);
        $rows = HousingRequest::with(['client', 'offers.demarcheur'])
            ->where('status', HousingStatus::Searching)
            ->whereIn('zone', $zones)
            ->latest('id')
            ->limit(30)
            ->get();

        return HousingRequestResource::collection($rows);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = HousingRequest::with(['client', 'offers.demarcheur'])
            ->where('demarcheur_id', $request->user()->id)
            ->latest('id')
            ->paginate(30);

        return HousingRequestResource::collection($rows);
    }

    public function offer(Request $request, HousingRequest $housingRequest): HousingRequestResource
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:8', 'max:500']]);
        $this->housing->offer($request->user(), $housingRequest, $data['message']);

        return new HousingRequestResource($housingRequest->fresh(['client', 'offers.demarcheur']));
    }

    public function complete(Request $request, HousingRequest $housingRequest): HousingRequestResource
    {
        return new HousingRequestResource($this->housing->complete($request->user(), $housingRequest));
    }

    public function cancel(Request $request, HousingRequest $housingRequest): HousingRequestResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new HousingRequestResource($this->housing->cancel($request->user(), $housingRequest, $data['reason'] ?? null));
    }
}
