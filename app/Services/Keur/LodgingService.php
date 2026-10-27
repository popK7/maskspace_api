<?php

namespace App\Services\Keur;

use App\Enums\BookingStatus;
use App\Enums\RentalBilling;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Lodging;
use App\Models\LodgingBooking;
use App\Models\User;
use App\Services\Media\ImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LodgingService
{
    public function __construct(private readonly ImageStorage $images) {}

    public function publish(User $host, array $data): Lodging
    {
        $this->assertHost($host);

        return Lodging::create([
            'host_id' => $host->id,
            'kind' => $data['kind'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'address' => $data['address'],
            'neighborhood' => $data['neighborhood'] ?? null,
            'city' => $data['city'] ?? 'Casablanca',
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'guests' => $data['guests'] ?? 2,
            'bedrooms' => $data['bedrooms'] ?? 1,
            'beds' => $data['beds'] ?? 1,
            'bathrooms' => $data['bathrooms'] ?? 1,
            'price_cents' => $data['price_cents'],
            'billing' => $data['billing'] ?? RentalBilling::Night->value,
            'amenities' => $data['amenities'] ?? [],
            'is_active' => false,
        ]);
    }

    public function update(User $host, Lodging $lodging, array $data): Lodging
    {
        $this->assertOwned($host, $lodging);
        $lodging->forceFill($data)->save();

        return $lodging->fresh('photos');
    }

    public function setActive(User $host, Lodging $lodging, bool $active): Lodging
    {
        $this->assertOwned($host, $lodging);
        if ($active && $lodging->photos()->doesntExist()) {
            throw new ApiException('Ajoutez au moins une photo avant de publier l’annonce.', 422, 'photos_required');
        }
        $lodging->forceFill(['is_active' => $active])->save();

        return $lodging->fresh('photos');
    }

    public function addPhoto(User $host, Lodging $lodging, UploadedFile $file): Lodging
    {
        $this->assertOwned($host, $lodging);
        $max = (int) config('shop.lodging_max_photos', 8);
        if ($lodging->photos()->count() >= $max) {
            throw ValidationException::withMessages(['image' => ["Maximum {$max} photos par logement."]]);
        }

        $path = $this->images->store($file, "lodgings/{$lodging->id}");
        $lodging->photos()->create([
            'path' => $path,
            'position' => (int) $lodging->photos()->max('position') + 1,
        ]);

        if (! $lodging->is_active) {
            $lodging->forceFill(['is_active' => true])->save();
        }

        return $lodging->fresh('photos');
    }

    public function deletePhoto(User $host, Lodging $lodging, int $photoId): Lodging
    {
        $this->assertOwned($host, $lodging);
        $photo = $lodging->photos()->findOrFail($photoId);
        $this->images->delete($photo->path);
        $photo->delete();

        if ($lodging->photos()->doesntExist()) {
            $lodging->forceFill(['is_active' => false])->save();
        }

        return $lodging->fresh('photos');
    }

    public function book(User $client, Lodging $lodging, array $data): LodgingBooking
    {
        if (! $lodging->is_active || $lodging->photos()->doesntExist()) {
            throw new ApiException('Cette annonce n’est plus disponible.', 409, 'lodging_unavailable');
        }

        $checkIn = \Illuminate\Support\Carbon::parse($data['check_in'])->startOfDay();
        $checkOut = \Illuminate\Support\Carbon::parse($data['check_out'])->startOfDay();
        if ($checkOut->lte($checkIn)) {
            throw new ApiException('La date de départ doit être après l’arrivée.', 422, 'invalid_dates');
        }
        if ($checkIn->lt(now()->startOfDay())) {
            throw new ApiException('La date d’arrivée est déjà passée.', 422, 'invalid_dates');
        }

        $guests = (int) ($data['guests'] ?? 1);
        if ($guests < 1 || $guests > $lodging->guests) {
            throw new ApiException('Ce logement n’accueille que '.$lodging->guests.' voyageur(s).', 422, 'too_many_guests');
        }

        $units = $this->units($lodging, $checkIn, $checkOut);

        return DB::transaction(function () use ($client, $lodging, $checkIn, $checkOut, $guests, $units, $data) {
            $overlap = LodgingBooking::where('lodging_id', $lodging->id)
                ->whereIn('status', BookingStatus::blocking())
                ->where('check_in', '<', $checkOut->toDateString())
                ->where('check_out', '>', $checkIn->toDateString())
                ->lockForUpdate()
                ->exists();
            if ($overlap) {
                throw new ApiException('Ces dates sont déjà réservées.', 409, 'dates_taken');
            }

            return LodgingBooking::create([
                'client_id' => $client->id,
                'host_id' => $lodging->host_id,
                'lodging_id' => $lodging->id,
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'guests' => $guests,
                'nights' => $units,
                'billing' => $lodging->billing,
                'total_cents' => $lodging->price_cents * $units,
                'status' => BookingStatus::Pending,
                'note' => $data['note'] ?? null,
            ]);
        });
    }

    public function accept(User $host, LodgingBooking $booking): LodgingBooking
    {
        return $this->decide($host, $booking, BookingStatus::Accepted, ['accepted_at' => now()]);
    }

    public function reject(User $host, LodgingBooking $booking, ?string $reason = null): LodgingBooking
    {
        return $this->decide($host, $booking, BookingStatus::Rejected, [
            'rejected_at' => now(),
            'cancel_reason' => $reason,
        ]);
    }

    public function complete(User $host, LodgingBooking $booking): LodgingBooking
    {
        if ($booking->host_id !== $host->id) {
            throw new ApiException('Réservation introuvable.', 404, 'booking_not_found');
        }
        if ($booking->status !== BookingStatus::Accepted) {
            throw new ApiException('Marquez le séjour une fois la réservation acceptée.', 409, 'booking_bad_status');
        }
        $booking->forceFill(['status' => BookingStatus::Completed, 'completed_at' => now()])->save();

        return $booking->fresh(['lodging.photos', 'client', 'host']);
    }

    public function cancel(User $actor, LodgingBooking $booking, ?string $reason = null): LodgingBooking
    {
        $asClient = $booking->client_id === $actor->id;
        $asHost = $booking->host_id === $actor->id;
        if (! $asClient && ! $asHost) {
            throw new ApiException('Réservation introuvable.', 404, 'booking_not_found');
        }
        if ($booking->status->isFinal()) {
            throw new ApiException('Cette réservation est déjà close.', 409, 'booking_closed');
        }
        $booking->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancelled_by' => $asClient ? 'client' : 'host',
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ])->save();

        return $booking->fresh(['lodging.photos', 'client', 'host']);
    }

    public function review(User $client, LodgingBooking $booking, int $rating, ?string $comment = null): LodgingBooking
    {
        if ($booking->client_id !== $client->id) {
            throw new ApiException('Réservation introuvable.', 404, 'booking_not_found');
        }
        if ($booking->status !== BookingStatus::Completed) {
            throw new ApiException('Vous pourrez noter une fois le séjour terminé.', 409, 'booking_not_completed');
        }
        if ($booking->rating !== null) {
            throw new ApiException('Vous avez déjà noté ce séjour.', 409, 'already_reviewed');
        }

        $booking->forceFill(['rating' => $rating, 'review' => $comment])->save();

        $lodging = $booking->lodging;
        $avg = LodgingBooking::where('lodging_id', $lodging->id)->whereNotNull('rating')->avg('rating');
        $count = LodgingBooking::where('lodging_id', $lodging->id)->whereNotNull('rating')->count();
        $lodging->forceFill([
            'rating_avg' => round((float) $avg, 2),
            'rating_count' => $count,
        ])->save();

        return $booking->fresh(['lodging.photos', 'client', 'host']);
    }

    private function decide(User $host, LodgingBooking $booking, BookingStatus $to, array $extra): LodgingBooking
    {
        return DB::transaction(function () use ($host, $booking, $to, $extra) {
            $booking = LodgingBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($booking->host_id !== $host->id) {
                throw new ApiException('Réservation introuvable.', 404, 'booking_not_found');
            }
            if ($booking->status !== BookingStatus::Pending) {
                throw new ApiException('Cette demande n’est plus en attente.', 409, 'booking_not_pending');
            }
            $booking->forceFill(['status' => $to, ...$extra])->save();

            return $booking->fresh(['lodging.photos', 'client', 'host']);
        });
    }

    private function units(Lodging $lodging, \Illuminate\Support\Carbon $checkIn, \Illuminate\Support\Carbon $checkOut): int
    {
        if ($lodging->billing === RentalBilling::Month) {
            $cursor = $checkIn->copy();
            $months = 0;
            while ($months < 25 && $cursor->lt($checkOut)) {
                $next = $cursor->copy()->addMonthsNoOverflow(1);
                if ($next->gt($checkOut)) {
                    break;
                }
                $cursor = $next;
                $months++;
            }
            if ($months < 1 || $months > 24 || ! $cursor->equalTo($checkOut)) {
                throw new ApiException('Une location mensuelle se réserve par mois entiers, de 1 à 24 mois.', 422, 'invalid_dates');
            }

            return $months;
        }

        $nights = (int) $checkIn->diffInDays($checkOut);
        if ($nights < 1 || $nights > 60) {
            throw new ApiException('Choisissez entre 1 et 60 nuits.', 422, 'invalid_dates');
        }

        return $nights;
    }

    private function assertHost(User $host): void
    {
        if (! $host->hasRole(UserRole::Hote)) {
            throw new ApiException('Compte hôte requis.', 403, 'forbidden_role');
        }
    }

    private function assertOwned(User $host, Lodging $lodging): void
    {
        $this->assertHost($host);
        if ($lodging->host_id !== $host->id) {
            throw new ApiException('Annonce introuvable.', 404, 'lodging_not_found');
        }
    }
}
