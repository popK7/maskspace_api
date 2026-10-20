<?php

namespace App\Http\Controllers\Api\V1\Medecin;

use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultationResource;
use App\Models\Consultation;
use App\Services\Care\ConsultationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConsultationController extends Controller
{
    public function __construct(private readonly ConsultationService $care) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $specialties = $this->care->specialtiesOf($user);
        $open = $user->is_available && $user->is_visible
            ? Consultation::where('status', ConsultationStatus::Searching)->whereIn('specialty', $specialties)->count()
            : 0;

        return response()->json(['data' => [
            'is_available' => (bool) $user->is_available,
            'specialties' => $specialties,
            'open_requests' => $open,
            'assigned' => Consultation::where('medecin_id', $user->id)->where('status', ConsultationStatus::Assigned)->count(),
        ]]);
    }

    public function available(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        if (! $user->is_available || ! $user->is_visible) {
            return ConsultationResource::collection(collect());
        }

        $rows = Consultation::with(['client', 'offers.medecin'])
            ->where('status', ConsultationStatus::Searching)
            ->whereIn('specialty', $this->care->specialtiesOf($user))
            ->latest('id')
            ->limit(30)
            ->get();

        return ConsultationResource::collection($rows);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = Consultation::with(['client', 'offers.medecin'])
            ->where('medecin_id', $request->user()->id)
            ->latest('id')
            ->paginate(30);

        return ConsultationResource::collection($rows);
    }

    public function offer(Request $request, Consultation $consultation): ConsultationResource
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:8', 'max:500']]);
        $this->care->offer($request->user(), $consultation, $data['message']);

        return new ConsultationResource($consultation->fresh(['client', 'offers.medecin']));
    }

    public function complete(Request $request, Consultation $consultation): ConsultationResource
    {
        return new ConsultationResource($this->care->complete($request->user(), $consultation));
    }

    public function cancel(Request $request, Consultation $consultation): ConsultationResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new ConsultationResource($this->care->cancel($request->user(), $consultation, $data['reason'] ?? null));
    }
}
