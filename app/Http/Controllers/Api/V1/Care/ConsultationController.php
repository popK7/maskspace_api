<?php

namespace App\Http\Controllers\Api\V1\Care;

use App\Enums\ConsultationMode;
use App\Enums\MedicalSpecialty;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultationResource;
use App\Models\Consultation;
use App\Services\Care\ConsultationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ConsultationController extends Controller
{
    public function __construct(private readonly ConsultationService $care) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'specialty' => ['required', Rule::enum(MedicalSpecialty::class)],
            'mode' => ['nullable', Rule::enum(ConsultationMode::class)],
            'region' => ['nullable', 'string', 'max:100'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $row = $this->care->publish($request->user(), $data);

        return (new ConsultationResource($row->load(['offers.medecin', 'client'])))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = Consultation::with(['offers.medecin', 'medecin', 'client'])
            ->where('client_id', $request->user()->id)
            ->latest('id')
            ->paginate(30);

        return ConsultationResource::collection($rows);
    }

    public function show(Request $request, Consultation $consultation): ConsultationResource
    {
        abort_unless($consultation->client_id === $request->user()->id, 404);

        return new ConsultationResource($consultation->load(['offers.medecin', 'medecin', 'client']));
    }

    public function choose(Request $request, Consultation $consultation): ConsultationResource
    {
        $data = $request->validate(['offer_id' => ['required', 'integer']]);

        return new ConsultationResource($this->care->choose($request->user(), $consultation, (int) $data['offer_id']));
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
