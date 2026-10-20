<?php

namespace App\Http\Controllers\Api\V1\Beaute;

use App\Enums\BeautyStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BeautyRequestResource;
use App\Models\BeautyRequest;
use App\Services\Beauty\BeautyService;
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
        $open = $user->is_available && $user->is_visible
            ? BeautyRequest::where('status', BeautyStatus::Searching)->whereIn('specialty', $specialties)->count()
            : 0;

        return response()->json(['data' => [
            'is_available' => (bool) $user->is_available,
            'specialties' => $specialties,
            'open_requests' => $open,
            'assigned' => BeautyRequest::where('pro_id', $user->id)->where('status', BeautyStatus::Assigned)->count(),
        ]]);
    }

    public function available(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        if (! $user->is_available || ! $user->is_visible) {
            return BeautyRequestResource::collection(collect());
        }

        $rows = BeautyRequest::with(['client', 'offers.pro'])
            ->where('status', BeautyStatus::Searching)
            ->whereIn('specialty', $this->beauty->specialtiesOf($user))
            ->latest('id')
            ->limit(30)
            ->get();

        return BeautyRequestResource::collection($rows);
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
