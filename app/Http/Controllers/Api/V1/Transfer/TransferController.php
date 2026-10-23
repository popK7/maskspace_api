<?php

namespace App\Http\Controllers\Api\V1\Transfer;

use App\Enums\TransferCurrency;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RideResource;
use App\Http\Resources\TransferResource;
use App\Models\MoneyTransfer;
use App\Services\Dispatch\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Transferts du client. */
class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transfers) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:100', 'max:50000000'],
            'currency' => ['required', 'string', Rule::enum(TransferCurrency::class)],
            'destination_country' => ['required', 'string', Rule::in(UserRole::Changeur->allowedSpecialties())],
            'address' => ['required', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $transfer = $this->transfers->publish($request->user(), $data);

        return (new TransferResource($transfer->load(['client', 'offers'])))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = MoneyTransfer::with([
            'changeur.profile',
            'offers.changeur.profile',
            'ride.driver.profile',
        ])
            ->where('client_id', $request->user()->id)
            ->latest('id')
            ->paginate(min(50, (int) $request->integer('per_page', 20)));

        return TransferResource::collection($rows);
    }

    public function show(Request $request, MoneyTransfer $transfer): TransferResource
    {
        $this->authorizeClient($request, $transfer);

        return new TransferResource($transfer->load([
            'client', 'changeur.profile', 'offers.changeur.profile', 'ride.driver.profile', 'ride.offeredDriver',
        ]));
    }

    public function choose(Request $request, MoneyTransfer $transfer): TransferResource
    {
        $this->authorizeClient($request, $transfer);
        $data = $request->validate(['offer_id' => ['required', 'integer']]);

        return new TransferResource($this->transfers->choose($request->user(), $transfer, (int) $data['offer_id']));
    }

    public function meet(Request $request, MoneyTransfer $transfer): TransferResource
    {
        $this->authorizeClient($request, $transfer);

        return new TransferResource($this->transfers->meet($request->user(), $transfer));
    }

    public function complete(Request $request, MoneyTransfer $transfer): TransferResource
    {
        $this->authorizeClient($request, $transfer);

        return new TransferResource($this->transfers->complete($request->user(), $transfer));
    }

    public function cancel(Request $request, MoneyTransfer $transfer): TransferResource
    {
        $this->authorizeClient($request, $transfer);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new TransferResource($this->transfers->cancel($request->user(), $transfer, $data['reason'] ?? null));
    }

    public function review(Request $request, MoneyTransfer $transfer): TransferResource
    {
        $this->authorizeClient($request, $transfer);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        return new TransferResource($this->transfers->review($request->user(), $transfer, (int) $data['rating'], $data['comment'] ?? null));
    }

    public function tracking(Request $request, MoneyTransfer $transfer): JsonResponse
    {
        $this->authorizeClient($request, $transfer);
        $transfer->load(['changeur.profile', 'ride.driver.profile']);

        return response()->json(['data' => $this->transfers->tracking($transfer)]);
    }

    public function requestRide(Request $request, MoneyTransfer $transfer): JsonResponse
    {
        $this->authorizeClient($request, $transfer);

        return $this->rideResponse($request, $transfer);
    }

    public function rideResponse(Request $request, MoneyTransfer $transfer): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'address' => ['nullable', 'string', 'max:255'],
            'driver_id' => ['nullable', 'integer'],
        ]);

        $lat = $data['latitude'] ?? $user->latitude;
        $lng = $data['longitude'] ?? $user->longitude;
        if ($lat === null || $lng === null) {
            throw new ApiException('Indiquez votre position pour qu’un transporteur vienne vous chercher.', 422, 'location_required');
        }

        $ride = $this->transfers->requestRide($user, $transfer, [
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
            'address' => $data['address'] ?? 'Ma position',
            'driver_id' => $data['driver_id'] ?? null,
        ]);

        $created = $ride->wasRecentlyCreated;

        return (new RideResource($ride->load(['passenger', 'offeredDriver.profile', 'transfer'])))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    private function authorizeClient(Request $request, MoneyTransfer $transfer): void
    {
        abort_unless($transfer->client_id === $request->user()->id, 404);
    }
}
