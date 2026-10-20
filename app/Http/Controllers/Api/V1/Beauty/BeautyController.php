<?php

namespace App\Http\Controllers\Api\V1\Beauty;

use App\Enums\BeautyMode;
use App\Enums\BeautySpecialty;
use App\Http\Controllers\Controller;
use App\Http\Resources\BeautyRequestResource;
use App\Models\BeautyRequest;
use App\Services\Beauty\BeautyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BeautyController extends Controller
{
    public function __construct(private readonly BeautyService $beauty) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'specialty' => ['required', Rule::enum(BeautySpecialty::class)],
            'mode' => ['nullable', Rule::enum(BeautyMode::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $row = $this->beauty->publish($request->user(), $data);

        return (new BeautyRequestResource($row->load(['offers.pro', 'client'])))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = BeautyRequest::with(['offers.pro', 'pro', 'client'])
            ->where('client_id', $request->user()->id)
            ->latest('id')
            ->paginate(30);

        return BeautyRequestResource::collection($rows);
    }

    public function show(Request $request, BeautyRequest $beautyRequest): BeautyRequestResource
    {
        abort_unless($beautyRequest->client_id === $request->user()->id, 404);

        return new BeautyRequestResource($beautyRequest->load(['offers.pro', 'pro', 'client']));
    }

    public function choose(Request $request, BeautyRequest $beautyRequest): BeautyRequestResource
    {
        $data = $request->validate(['offer_id' => ['required', 'integer']]);

        return new BeautyRequestResource($this->beauty->choose($request->user(), $beautyRequest, (int) $data['offer_id']));
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
