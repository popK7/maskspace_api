<?php

namespace App\Http\Controllers\Api\V1\Keur;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Lodging;
use App\Models\LodgingBooking;
use App\Services\Keur\LodgingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Réservations du client. */
class BookingController extends Controller
{
    public function __construct(private readonly LodgingService $keur) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lodging_id' => ['required', 'integer'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $lodging = Lodging::with('photos')->findOrFail($data['lodging_id']);
        $booking = $this->keur->book($request->user(), $lodging, $data);

        return (new BookingResource($booking->load(['lodging.photos', 'client', 'host'])))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = LodgingBooking::with(['lodging.photos', 'host'])
            ->where('client_id', $request->user()->id)
            ->latest('id')
            ->paginate(min(50, (int) $request->integer('per_page', 20)));

        return BookingResource::collection($rows);
    }

    public function show(Request $request, LodgingBooking $booking): BookingResource
    {
        abort_unless($booking->client_id === $request->user()->id, 404);

        return new BookingResource($booking->load(['lodging.photos', 'client', 'host']));
    }

    public function cancel(Request $request, LodgingBooking $booking): BookingResource
    {
        abort_unless($booking->client_id === $request->user()->id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new BookingResource($this->keur->cancel($request->user(), $booking, $data['reason'] ?? null));
    }

    public function review(Request $request, LodgingBooking $booking): BookingResource
    {
        abort_unless($booking->client_id === $request->user()->id, 404);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        return new BookingResource($this->keur->review($request->user(), $booking, (int) $data['rating'], $data['comment'] ?? null));
    }
}
