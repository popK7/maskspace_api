<?php

namespace App\Http\Controllers\Api\V1\Changeur;

use App\Enums\JobOfferStatus;
use App\Enums\TransferStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RideResource;
use App\Http\Resources\TransferResource;
use App\Models\MoneyTransfer;
use App\Models\TransferOffer;
use App\Services\Dispatch\TransferService;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transfers) {}

    public function available(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('profile');
        if (! $user->is_available || ! $user->is_visible) {
            return response()->json(['data' => [], 'meta' => ['is_available' => false]]);
        }

        $corridors = $user->profile?->specialties ?? [];
        $offered = TransferOffer::where('changeur_id', $user->id)->pluck('transfer_id');
        $radius = (float) config('job.radius_km', 15);

        $rows = MoneyTransfer::with('client')
            ->where('status', TransferStatus::Searching)
            ->whereIn('destination_country', $corridors ?: ['__none__'])
            ->whereNotIn('id', $offered)
            ->latest('id')->limit(50)->get()
            ->map(function (MoneyTransfer $transfer) use ($user, $radius) {
                $km = $user->hasLocation()
                    ? round(Geo::km($user->latitude, $user->longitude, $transfer->latitude, $transfer->longitude), 2)
                    : null;
                if ($km !== null && $km > $radius) {
                    return null;
                }

                return [
                    'id' => $transfer->id,
                    'amount' => $transfer->amount,
                    'currency' => $transfer->currency->value,
                    'destination_country' => $transfer->destination_country,
                    'note' => $transfer->note,
                    'neighborhood' => $transfer->neighborhood,
                    'city' => $transfer->city,
                    'client' => ['name' => $transfer->client?->name],
                    'distance_km' => $km,
                    'eta_minutes' => $km !== null ? Geo::etaMinutes($km) : null,
                    'created_at' => $transfer->created_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->sortBy(fn ($a) => $a['distance_km'] ?? INF)
            ->values();

        return response()->json(['data' => $rows, 'meta' => ['is_available' => true]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = MoneyTransfer::with(['client', 'ride.driver.profile', 'ride.offeredDriver'])
            ->where('changeur_id', $request->user()->id)
            ->latest('id')
            ->paginate(min(50, (int) $request->integer('per_page', 20)));

        return TransferResource::collection($rows);
    }

    public function show(Request $request, MoneyTransfer $transfer): TransferResource
    {
        $this->authorizeChangeur($request, $transfer);

        return new TransferResource($transfer->load([
            'client', 'changeur.profile', 'ride.driver.profile', 'ride.offeredDriver',
        ]));
    }

    public function offer(Request $request, MoneyTransfer $transfer): JsonResponse
    {
        $data = $request->validate(['eta_minutes' => ['nullable', 'integer', 'between:1,180']]);
        $offer = $this->transfers->offer($request->user(), $transfer, $data['eta_minutes'] ?? null);

        return response()->json(['data' => [
            'id' => $offer->id,
            'status' => $offer->status->value,
            'eta_minutes' => $offer->eta_minutes,
            'distance_km' => $offer->distance_km,
            'transfer_id' => $offer->transfer_id,
        ]], 201);
    }

    public function withdraw(Request $request, MoneyTransfer $transfer): JsonResponse
    {
        $this->transfers->withdraw($request->user(), $transfer);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function meet(Request $request, MoneyTransfer $transfer): TransferResource
    {
        $this->authorizeChangeur($request, $transfer);

        return new TransferResource($this->transfers->meet($request->user(), $transfer));
    }

    public function complete(Request $request, MoneyTransfer $transfer): TransferResource
    {
        $this->authorizeChangeur($request, $transfer);

        return new TransferResource($this->transfers->complete($request->user(), $transfer));
    }

    public function cancel(Request $request, MoneyTransfer $transfer): TransferResource
    {
        $this->authorizeChangeur($request, $transfer);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new TransferResource($this->transfers->cancel($request->user(), $transfer, $data['reason'] ?? null));
    }

    public function requestRide(Request $request, MoneyTransfer $transfer): JsonResponse
    {
        $this->authorizeChangeur($request, $transfer);
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

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = [now()->startOfDay(), now()->endOfDay()];
        $completedToday = MoneyTransfer::where('changeur_id', $user->id)->where('status', TransferStatus::Completed)
            ->whereBetween('completed_at', $today)->count();
        $lifetime = MoneyTransfer::where('changeur_id', $user->id)->where('status', TransferStatus::Completed)->count();
        $rating = MoneyTransfer::where('changeur_id', $user->id)->whereNotNull('rating')->avg('rating');
        $active = MoneyTransfer::where('changeur_id', $user->id)->whereIn('status', TransferStatus::underway())->count();
        $pendingOffers = TransferOffer::where('changeur_id', $user->id)->where('status', JobOfferStatus::Pending)->count();

        return response()->json(['data' => [
            'is_available' => (bool) $user->is_available,
            'today_transfers' => $completedToday,
            'lifetime_transfers' => $lifetime,
            'rating' => $rating !== null ? round((float) $rating, 1) : null,
            'active_count' => $active,
            'pending_offers' => $pendingOffers,
        ]]);
    }

    private function authorizeChangeur(Request $request, MoneyTransfer $transfer): void
    {
        abort_unless($transfer->changeur_id === $request->user()->id, 404);
    }
}
