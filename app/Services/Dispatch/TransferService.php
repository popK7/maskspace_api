<?php

namespace App\Services\Dispatch;

use App\Enums\JobOfferStatus;
use App\Enums\RideStatus;
use App\Enums\TransferStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\MoneyTransfer;
use App\Models\Ride;
use App\Models\TransferOffer;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

class TransferService
{
    public function __construct(
        private readonly LocationPing $ping,
        private readonly RideService $rides,
    ) {}

    public function publish(User $client, array $data): MoneyTransfer
    {
        $this->assertNoActive($client, 'client_id', 'Vous avez déjà un transfert en cours.');

        return MoneyTransfer::create([
            'client_id' => $client->id,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'destination_country' => $data['destination_country'],
            'status' => TransferStatus::Searching,
            'note' => $data['note'] ?? null,
            'address' => $data['address'],
            'neighborhood' => $data['neighborhood'] ?? null,
            'city' => $data['city'] ?? null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ]);
    }

    public function offer(User $changeur, MoneyTransfer $transfer, ?int $etaMinutes = null): TransferOffer
    {
        $changeur->loadMissing('profile');
        $this->assertChangeurReady($changeur, $transfer->destination_country);

        return DB::transaction(function () use ($changeur, $transfer, $etaMinutes) {
            $transfer = MoneyTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== TransferStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'transfer_not_searching');
            }

            $existing = TransferOffer::where('transfer_id', $transfer->id)->where('changeur_id', $changeur->id)->first();
            if ($existing && $existing->status === JobOfferStatus::Pending) {
                return $existing;
            }
            if ($existing) {
                throw new ApiException('Vous ne pouvez plus vous proposer sur cette demande.', 409, 'offer_closed');
            }

            $km = $changeur->hasLocation()
                ? round(Geo::km($changeur->latitude, $changeur->longitude, $transfer->latitude, $transfer->longitude), 2)
                : null;

            return TransferOffer::create([
                'transfer_id' => $transfer->id,
                'changeur_id' => $changeur->id,
                'status' => JobOfferStatus::Pending,
                'eta_minutes' => $etaMinutes ?? ($km !== null ? Geo::etaMinutes($km) : 15),
                'distance_km' => $km,
            ]);
        });
    }

    public function withdraw(User $changeur, MoneyTransfer $transfer): void
    {
        $offer = TransferOffer::where('transfer_id', $transfer->id)->where('changeur_id', $changeur->id)->first();
        if (! $offer || $offer->status !== JobOfferStatus::Pending) {
            throw new ApiException('Aucune proposition à retirer.', 409, 'offer_not_pending');
        }
        $offer->forceFill(['status' => JobOfferStatus::Withdrawn])->save();
    }

    public function choose(User $client, MoneyTransfer $transfer, int $offerId): MoneyTransfer
    {
        return DB::transaction(function () use ($client, $transfer, $offerId) {
            $transfer = MoneyTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->client_id !== $client->id) {
                throw new ApiException('Transfert introuvable.', 404, 'transfer_not_found');
            }
            if ($transfer->status !== TransferStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'transfer_not_searching');
            }

            $offer = TransferOffer::where('transfer_id', $transfer->id)->whereKey($offerId)->lockForUpdate()->firstOrFail();
            if ($offer->status !== JobOfferStatus::Pending) {
                throw new ApiException('Cette proposition n’est plus valable.', 409, 'offer_not_pending');
            }

            $changeur = User::whereKey($offer->changeur_id)->lockForUpdate()->firstOrFail();
            $changeur->loadMissing('profile');
            $this->assertChangeurReady($changeur, $transfer->destination_country);

            $transfer->forceFill([
                'changeur_id' => $changeur->id,
                'status' => TransferStatus::Assigned,
                'eta_minutes' => $offer->eta_minutes,
                'assigned_at' => now(),
            ])->save();

            $offer->forceFill(['status' => JobOfferStatus::Chosen])->save();
            TransferOffer::where('transfer_id', $transfer->id)->where('id', '!=', $offer->id)
                ->where('status', JobOfferStatus::Pending)
                ->update(['status' => JobOfferStatus::Declined]);

            return $transfer->fresh(['changeur.profile', 'client', 'offers.changeur.profile']);
        });
    }

    public function meet(User $actor, MoneyTransfer $transfer): MoneyTransfer
    {
        $this->assertParty($transfer, $actor);
        if (! in_array($transfer->status, [TransferStatus::Assigned, TransferStatus::Meeting], true)) {
            throw new ApiException('La rencontre n’est pas encore possible.', 409, 'transfer_bad_status');
        }

        if ($transfer->status === TransferStatus::Assigned) {
            $transfer->forceFill(['status' => TransferStatus::Meeting, 'meeting_at' => now()])->save();
        }

        return $transfer->fresh(['changeur.profile', 'client', 'ride.driver.profile']);
    }

    public function complete(User $actor, MoneyTransfer $transfer): MoneyTransfer
    {
        $this->assertParty($transfer, $actor);
        if (! in_array($transfer->status, [TransferStatus::Assigned, TransferStatus::Meeting], true)) {
            throw new ApiException('Cette action n’est pas possible maintenant.', 409, 'transfer_bad_status');
        }

        $transfer->forceFill([
            'status' => TransferStatus::Completed,
            'completed_at' => now(),
            'meeting_at' => $transfer->meeting_at ?? now(),
        ])->save();

        return $transfer->fresh(['changeur.profile', 'client', 'ride.driver.profile']);
    }

    public function cancel(User $actor, MoneyTransfer $transfer, ?string $reason = null): MoneyTransfer
    {
        return DB::transaction(function () use ($actor, $transfer, $reason) {
            $transfer = MoneyTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status->isFinal()) {
                throw new ApiException('Ce transfert est déjà clos.', 409, 'transfer_closed');
            }
            if ($transfer->status === TransferStatus::Meeting) {
                throw new ApiException('La rencontre a déjà commencé.', 409, 'transfer_started');
            }

            $asClient = $transfer->client_id === $actor->id;
            $asChangeur = $transfer->changeur_id === $actor->id;
            if (! $asClient && ! $asChangeur) {
                throw new ApiException('Transfert introuvable.', 404, 'transfer_not_found');
            }

            $transfer->forceFill([
                'status' => TransferStatus::Cancelled,
                'cancelled_by' => $asClient ? 'client' : 'changeur',
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
            ])->save();

            $ride = $transfer->ride_id ? Ride::whereKey($transfer->ride_id)->lockForUpdate()->first() : null;
            if ($ride && ! $ride->status->isFinal()) {
                $this->rides->cancelLocked($ride, 'Transfert annulé.');
            }

            return $transfer->fresh(['changeur.profile', 'client']);
        });
    }

    public function review(User $client, MoneyTransfer $transfer, int $rating, ?string $comment = null): MoneyTransfer
    {
        if ($transfer->client_id !== $client->id) {
            throw new ApiException('Transfert introuvable.', 404, 'transfer_not_found');
        }
        if ($transfer->status !== TransferStatus::Completed) {
            throw new ApiException('Vous pourrez noter une fois le transfert terminé.', 409, 'transfer_not_completed');
        }
        if ($transfer->rating !== null) {
            throw new ApiException('Vous avez déjà noté ce transfert.', 409, 'already_reviewed');
        }

        $transfer->forceFill(['rating' => $rating, 'review' => $comment])->save();

        return $transfer->fresh(['changeur.profile', 'client']);
    }

    public function requestRide(User $actor, MoneyTransfer $transfer, array $pickup): Ride
    {
        $this->assertParty($transfer, $actor);
        if (! in_array($transfer->status, TransferStatus::underway(), true)) {
            throw new ApiException('Appelez un transporteur une fois mis en relation.', 409, 'transfer_bad_status');
        }

        $searching = Ride::where('transfer_id', $transfer->id)->where('status', RideStatus::Searching)->first();
        if ($searching) {
            if ($searching->passenger_id === $actor->id) {
                return $this->rides->retarget($actor, $searching, $pickup['driver_id'] ?? null);
            }
            throw new ApiException('Un transporteur est déjà en recherche pour cette rencontre.', 409, 'ride_already_active');
        }

        $active = Ride::where('transfer_id', $transfer->id)->whereIn('status', RideStatus::active())->exists();
        if ($active) {
            throw new ApiException('Un transporteur est déjà en route ou en recherche.', 409, 'ride_already_active');
        }

        [$plat, $plng] = $this->pickupNear($actor, $transfer, (float) $pickup['latitude'], (float) $pickup['longitude']);

        $ride = $this->rides->request($actor, [
            'transfer_id' => $transfer->id,
            'pickup_address' => $pickup['address'] ?? $actor->name,
            'pickup_latitude' => $plat,
            'pickup_longitude' => $plng,
            'dropoff_address' => $transfer->address,
            'dropoff_latitude' => $transfer->latitude,
            'dropoff_longitude' => $transfer->longitude,
            'driver_id' => $pickup['driver_id'] ?? null,
        ]);

        $transfer->forceFill(['ride_id' => $ride->id])->save();

        return $ride;
    }

    /** @return array<string, mixed> */
    public function tracking(MoneyTransfer $transfer): array
    {
        $transfer->loadMissing('changeur');
        $changeurLive = $transfer->changeur_id ? $this->ping->live($transfer->changeur_id) : null;
        $ride = $transfer->ride_id ? Ride::with('driver.profile')->find($transfer->ride_id) : $transfer->ride;

        $eta = $transfer->eta_minutes;
        if ($changeurLive && empty($changeurLive['stale'])) {
            $eta = Geo::etaMinutes(Geo::km(
                $changeurLive['latitude'], $changeurLive['longitude'],
                $transfer->latitude, $transfer->longitude,
            ));
        }
        $rideTrack = $ride ? $this->rides->tracking($ride) : null;
        if (is_array($rideTrack['eta'] ?? null) && ($rideTrack['phase'] ?? null) === 'to_dropoff') {
            $eta = $rideTrack['eta']['minutes'] ?? $eta;
        }

        return [
            'transfer_id' => $transfer->id,
            'status' => $transfer->status->value,
            'eta_minutes' => $eta,
            'destination' => [
                'address' => $transfer->address,
                'latitude' => $transfer->latitude,
                'longitude' => $transfer->longitude,
            ],
            'changeur' => $transfer->changeur ? [
                'id' => $transfer->changeur->id,
                'name' => $transfer->changeur->name,
                'phone' => $transfer->changeur->phone,
                'position' => $changeurLive,
            ] : null,
            'ride' => $rideTrack,
            'server_time' => now()->toIso8601String(),
        ];
    }

    public function markMeeting(MoneyTransfer $transfer): void
    {
        if ($transfer->status === TransferStatus::Assigned) {
            $transfer->forceFill(['status' => TransferStatus::Meeting, 'meeting_at' => now()])->save();
        }
    }

    private function assertParty(MoneyTransfer $transfer, User $actor): void
    {
        if ($transfer->client_id !== $actor->id && $transfer->changeur_id !== $actor->id) {
            throw new ApiException('Transfert introuvable.', 404, 'transfer_not_found');
        }
    }

    private function assertNoActive(User $user, string $column, string $message): void
    {
        $exists = MoneyTransfer::where($column, $user->id)->whereIn('status', TransferStatus::active())->exists();
        if ($exists) {
            throw new ApiException($message, 409, 'transfer_already_active');
        }
    }

    private function assertChangeurReady(User $changeur, string $country): void
    {
        if ($changeur->role !== UserRole::Changeur) {
            throw new ApiException('Compte de transfert d’argent requis.', 403, 'forbidden_role');
        }
        if (! $changeur->is_available) {
            throw new ApiException('Passez en service pour recevoir des demandes.', 409, 'changeur_offline');
        }
        $corridors = $changeur->profile?->specialties ?? [];
        if (! in_array($country, $corridors, true)) {
            throw new ApiException('Ce pays ne fait pas partie de vos corridors.', 409, 'corridor_mismatch');
        }

        if (MoneyTransfer::where('changeur_id', $changeur->id)->whereIn('status', TransferStatus::underway())->exists()) {
            throw new ApiException('Vous avez déjà un transfert en cours.', 409, 'transfer_already_active');
        }
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function pickupNear(User $actor, MoneyTransfer $transfer, float $lat, float $lng): array
    {
        $max = (float) config('job.radius_km', 15) * 3;
        $fromGps = Geo::km($lat, $lng, $transfer->latitude, $transfer->longitude);
        if ($fromGps <= $max || ! $actor->hasLocation()) {
            return [$lat, $lng];
        }

        $fromSaved = Geo::km($actor->latitude, $actor->longitude, $transfer->latitude, $transfer->longitude);
        if ($fromSaved < $fromGps) {
            return [(float) $actor->latitude, (float) $actor->longitude];
        }

        return [$lat, $lng];
    }
}
