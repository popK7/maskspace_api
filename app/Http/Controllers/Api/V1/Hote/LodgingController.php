<?php

namespace App\Http\Controllers\Api\V1\Hote;

use App\Enums\BookingStatus;
use App\Enums\LodgingKind;
use App\Enums\RentalBilling;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\LodgingResource;
use App\Models\Lodging;
use App\Models\LodgingBooking;
use App\Services\Keur\LodgingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LodgingController extends Controller
{
    public function __construct(private readonly LodgingService $keur) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = Lodging::with('photos')
            ->where('host_id', $request->user()->id)
            ->latest('id')
            ->paginate(min(50, (int) $request->integer('per_page', 20)));

        return LodgingResource::collection($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $lodging = $this->keur->publish($request->user(), $this->validated($request));

        return (new LodgingResource($lodging->load('photos')))->response()->setStatusCode(201);
    }

    public function show(Request $request, Lodging $lodging): LodgingResource
    {
        abort_unless($lodging->host_id === $request->user()->id, 404);

        return new LodgingResource($lodging->load(['photos', 'host']));
    }

    public function update(Request $request, Lodging $lodging): LodgingResource
    {
        return new LodgingResource($this->keur->update($request->user(), $lodging, $this->validated($request, true)));
    }

    public function availability(Request $request, Lodging $lodging): LodgingResource
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        return new LodgingResource($this->keur->setActive($request->user(), $lodging, (bool) $data['is_active']));
    }

    public function addPhoto(Request $request, Lodging $lodging): LodgingResource
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('shop.image_max_kb')],
        ]);

        return new LodgingResource($this->keur->addPhoto($request->user(), $lodging, $request->file('image')));
    }

    public function deletePhoto(Request $request, Lodging $lodging, int $photo): LodgingResource
    {
        return new LodgingResource($this->keur->deletePhoto($request->user(), $lodging, $photo));
    }

    public function bookings(Request $request): AnonymousResourceCollection
    {
        $rows = LodgingBooking::with(['lodging.photos', 'client'])
            ->where('host_id', $request->user()->id)
            ->latest('id')
            ->paginate(min(50, (int) $request->integer('per_page', 20)));

        return BookingResource::collection($rows);
    }

    public function showBooking(Request $request, LodgingBooking $booking): BookingResource
    {
        abort_unless($booking->host_id === $request->user()->id, 404);

        return new BookingResource($booking->load(['lodging.photos', 'client', 'host']));
    }

    public function accept(Request $request, LodgingBooking $booking): BookingResource
    {
        return new BookingResource($this->keur->accept($request->user(), $booking));
    }

    public function reject(Request $request, LodgingBooking $booking): BookingResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new BookingResource($this->keur->reject($request->user(), $booking, $data['reason'] ?? null));
    }

    public function complete(Request $request, LodgingBooking $booking): BookingResource
    {
        return new BookingResource($this->keur->complete($request->user(), $booking));
    }

    public function cancel(Request $request, LodgingBooking $booking): BookingResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new BookingResource($this->keur->cancel($request->user(), $booking, $data['reason'] ?? null));
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $pending = LodgingBooking::where('host_id', $user->id)->where('status', BookingStatus::Pending)->count();
        $accepted = LodgingBooking::where('host_id', $user->id)->where('status', BookingStatus::Accepted)->count();
        $listings = Lodging::where('host_id', $user->id)->where('is_active', true)->count();

        return response()->json(['data' => [
            'listings' => $listings,
            'pending_bookings' => $pending,
            'accepted_bookings' => $accepted,
        ]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        return $request->validate([
            'kind' => [...$sometimes, 'required', Rule::enum(LodgingKind::class)],
            'title' => [...$sometimes, 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'address' => [...$sometimes, 'required', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'latitude' => [...$sometimes, 'required', 'numeric', 'between:-90,90'],
            'longitude' => [...$sometimes, 'required', 'numeric', 'between:-180,180'],
            'guests' => ['nullable', 'integer', 'between:1,20'],
            'bedrooms' => ['nullable', 'integer', 'between:0,20'],
            'beds' => ['nullable', 'integer', 'between:1,20'],
            'bathrooms' => ['nullable', 'integer', 'between:1,10'],
            'price_cents' => [...$sometimes, 'required', 'integer', 'min:1000', 'max:50000000'],
            'billing' => ['nullable', Rule::enum(RentalBilling::class)],
            'amenities' => ['nullable', 'array', 'max:20'],
            'amenities.*' => ['string', 'max:40'],
        ]);
    }
}
