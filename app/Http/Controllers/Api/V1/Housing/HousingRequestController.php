<?php

namespace App\Http\Controllers\Api\V1\Housing;

use App\Enums\HousingKind;
use App\Enums\HousingZone;
use App\Enums\RentalBilling;
use App\Http\Controllers\Controller;
use App\Http\Resources\HousingRequestResource;
use App\Models\HousingRequest;
use App\Services\Housing\HousingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class HousingRequestController extends Controller
{
    public function __construct(private readonly HousingService $housing) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(HousingKind::class)],
            'zone' => ['required', Rule::enum(HousingZone::class)],
            'billing' => ['nullable', Rule::enum(RentalBilling::class)],
            'budget_cents' => ['nullable', 'integer', 'min:1000', 'max:50000000'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $row = $this->housing->publish($request->user(), $data);

        return (new HousingRequestResource($row->load(['offers.demarcheur', 'client'])))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = HousingRequest::with(['offers.demarcheur', 'demarcheur', 'client'])
            ->where('client_id', $request->user()->id)
            ->latest('id')
            ->paginate(30);

        return HousingRequestResource::collection($rows);
    }

    public function show(Request $request, HousingRequest $housingRequest): HousingRequestResource
    {
        abort_unless($housingRequest->client_id === $request->user()->id, 404);

        return new HousingRequestResource($housingRequest->load(['offers.demarcheur', 'demarcheur', 'client']));
    }

    public function choose(Request $request, HousingRequest $housingRequest): HousingRequestResource
    {
        $data = $request->validate(['offer_id' => ['required', 'integer']]);

        return new HousingRequestResource($this->housing->choose($request->user(), $housingRequest, (int) $data['offer_id']));
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
