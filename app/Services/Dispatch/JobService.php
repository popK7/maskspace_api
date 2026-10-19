<?php

namespace App\Services\Dispatch;

use App\Enums\JobOfferStatus;
use App\Enums\JobStatus;
use App\Enums\RideStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Job;
use App\Models\JobOffer;
use App\Models\Ride;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

class JobService
{
    public function __construct(
        private readonly LocationPing $ping,
        private readonly RideService $rides,
    ) {}

    public function publish(User $client, array $data): Job
    {
        $this->assertNoActive($client, 'client_id', 'Vous avez déjà une intervention en cours.');

        return Job::create([
            'client_id' => $client->id,
            'trade' => $data['trade'],
            'status' => JobStatus::Searching,
            'note' => $data['note'] ?? null,
            'address' => $data['address'],
            'neighborhood' => $data['neighborhood'] ?? null,
            'city' => $data['city'] ?? null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ]);
    }

    public function offer(User $artisan, Job $job, ?int $etaMinutes = null): JobOffer
    {
        $artisan->loadMissing('profile');
        $this->assertArtisanReady($artisan, $job->trade);

        return DB::transaction(function () use ($artisan, $job, $etaMinutes) {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            if ($job->status !== JobStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'job_not_searching');
            }

            $existing = JobOffer::where('job_id', $job->id)->where('artisan_id', $artisan->id)->first();
            if ($existing && $existing->status === JobOfferStatus::Pending) {
                return $existing;
            }
            if ($existing) {
                throw new ApiException('Vous ne pouvez plus vous proposer sur cette demande.', 409, 'offer_closed');
            }

            $km = $artisan->hasLocation()
                ? round(Geo::km($artisan->latitude, $artisan->longitude, $job->latitude, $job->longitude), 2)
                : null;

            return JobOffer::create([
                'job_id' => $job->id,
                'artisan_id' => $artisan->id,
                'status' => JobOfferStatus::Pending,
                'eta_minutes' => $etaMinutes ?? ($km !== null ? Geo::etaMinutes($km) : 15),
                'distance_km' => $km,
            ]);
        });
    }

    public function withdraw(User $artisan, Job $job): void
    {
        $offer = JobOffer::where('job_id', $job->id)->where('artisan_id', $artisan->id)->first();
        if (! $offer || $offer->status !== JobOfferStatus::Pending) {
            throw new ApiException('Aucune proposition à retirer.', 409, 'offer_not_pending');
        }
        $offer->forceFill(['status' => JobOfferStatus::Withdrawn])->save();
    }

    public function choose(User $client, Job $job, int $offerId): Job
    {
        return DB::transaction(function () use ($client, $job, $offerId) {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            if ($job->client_id !== $client->id) {
                throw new ApiException('Intervention introuvable.', 404, 'job_not_found');
            }
            if ($job->status !== JobStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'job_not_searching');
            }

            $offer = JobOffer::where('job_id', $job->id)->whereKey($offerId)->lockForUpdate()->firstOrFail();
            if ($offer->status !== JobOfferStatus::Pending) {
                throw new ApiException('Cette proposition n’est plus valable.', 409, 'offer_not_pending');
            }

            $artisan = User::whereKey($offer->artisan_id)->lockForUpdate()->firstOrFail();
            $artisan->loadMissing('profile');
            $this->assertArtisanReady($artisan, $job->trade);

            $job->forceFill([
                'artisan_id' => $artisan->id,
                'status' => JobStatus::Assigned,
                'eta_minutes' => $offer->eta_minutes,
                'assigned_at' => now(),
            ])->save();

            $offer->forceFill(['status' => JobOfferStatus::Chosen])->save();
            JobOffer::where('job_id', $job->id)->where('id', '!=', $offer->id)
                ->where('status', JobOfferStatus::Pending)
                ->update(['status' => JobOfferStatus::Declined]);

            return $job->fresh(['artisan.profile', 'client', 'offers.artisan.profile']);
        });
    }

    public function go(User $artisan, Job $job): Job
    {
        return $this->transition($job, $artisan, JobStatus::Assigned, JobStatus::EnRoute, ['en_route_at' => now()]);
    }

    public function arrive(User $artisan, Job $job): Job
    {
        $job = Job::whereKey($job->id)->firstOrFail();
        if (! in_array($job->status, [JobStatus::Assigned, JobStatus::EnRoute], true)) {
            throw new ApiException('Vous ne pouvez pas signaler votre arrivée maintenant.', 409, 'job_bad_status');
        }
        $this->assertOwner($job, artisan: $artisan);

        $job->forceFill(['status' => JobStatus::Arrived, 'arrived_at' => now()])->save();

        return $job->fresh(['artisan.profile', 'client', 'ride.driver.profile']);
    }

    public function start(User $artisan, Job $job): Job
    {
        return $this->transition($job, $artisan, JobStatus::Arrived, JobStatus::InProgress, ['started_at' => now()]);
    }

    public function complete(User $artisan, Job $job): Job
    {
        return $this->transition($job, $artisan, JobStatus::InProgress, JobStatus::Completed, ['completed_at' => now()]);
    }

    public function cancel(User $actor, Job $job, ?string $reason = null): Job
    {
        return DB::transaction(function () use ($actor, $job, $reason) {
            $job = Job::whereKey($job->id)->lockForUpdate()->firstOrFail();
            if ($job->status->isFinal()) {
                throw new ApiException('Cette intervention est déjà close.', 409, 'job_closed');
            }
            if (in_array($job->status, [JobStatus::Arrived, JobStatus::InProgress], true)) {
                throw new ApiException('L’intervention a déjà commencé.', 409, 'job_started');
            }

            $asClient = $job->client_id === $actor->id;
            $asArtisan = $job->artisan_id === $actor->id;
            if (! $asClient && ! $asArtisan) {
                throw new ApiException('Intervention introuvable.', 404, 'job_not_found');
            }

            $job->forceFill([
                'status' => JobStatus::Cancelled,
                'cancelled_by' => $asClient ? 'client' : 'artisan',
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
            ])->save();

            $ride = $job->ride_id ? Ride::whereKey($job->ride_id)->lockForUpdate()->first() : null;
            if ($ride && ! $ride->status->isFinal()) {
                $this->rides->cancelLocked($ride, 'Intervention annulée.');
            }

            return $job->fresh(['artisan.profile', 'client']);
        });
    }

    public function review(User $client, Job $job, int $rating, ?string $comment = null): Job
    {
        if ($job->client_id !== $client->id) {
            throw new ApiException('Intervention introuvable.', 404, 'job_not_found');
        }
        if ($job->status !== JobStatus::Completed) {
            throw new ApiException('Vous pourrez noter une fois l’intervention terminée.', 409, 'job_not_completed');
        }
        if ($job->rating !== null) {
            throw new ApiException('Vous avez déjà noté cette intervention.', 409, 'already_reviewed');
        }

        $job->forceFill(['rating' => $rating, 'review' => $comment])->save();

        return $job->fresh(['artisan.profile', 'client']);
    }

    public function requestRide(User $artisan, Job $job, array $pickup): Ride
    {
        $this->assertOwner($job, artisan: $artisan);
        if (! in_array($job->status, [JobStatus::Assigned, JobStatus::EnRoute], true)) {
            throw new ApiException('Appelez un transporteur une fois la mission acceptée, avant d’arriver chez le client.', 409, 'job_bad_status');
        }

        $searching = Ride::where('job_id', $job->id)->where('status', RideStatus::Searching)->first();
        if ($searching) {
            return $this->rides->retarget($artisan, $searching, $pickup['driver_id'] ?? null);
        }

        $active = Ride::where('job_id', $job->id)->whereIn('status', RideStatus::active())->exists();
        if ($active) {
            throw new ApiException('Un transporteur est déjà en route ou en recherche.', 409, 'ride_already_active');
        }

        [$plat, $plng] = $this->pickupNearJob($artisan, $job, (float) $pickup['latitude'], (float) $pickup['longitude']);

        $ride = $this->rides->request($artisan, [
            'job_id' => $job->id,
            'pickup_address' => $pickup['address'] ?? $artisan->name,
            'pickup_latitude' => $plat,
            'pickup_longitude' => $plng,
            'dropoff_address' => $job->address,
            'dropoff_latitude' => $job->latitude,
            'dropoff_longitude' => $job->longitude,
            'driver_id' => $pickup['driver_id'] ?? null,
        ]);

        $job->forceFill(['ride_id' => $ride->id])->save();

        return $ride;
    }

    /** @return array<string, mixed> */
    public function tracking(Job $job): array
    {
        $job->loadMissing('artisan');
        $artisanLive = $job->artisan_id ? $this->ping->live($job->artisan_id) : null;
        $ride = $job->ride_id ? Ride::with('driver.profile')->find($job->ride_id) : $job->ride;

        $eta = $job->eta_minutes;
        if ($artisanLive && empty($artisanLive['stale'])) {
            $eta = Geo::etaMinutes(Geo::km(
                $artisanLive['latitude'], $artisanLive['longitude'],
                $job->latitude, $job->longitude,
            ));
        }
        $rideTrack = $ride ? $this->rides->tracking($ride) : null;
        if (is_array($rideTrack['eta'] ?? null) && ($rideTrack['phase'] ?? null) === 'to_dropoff') {
            $eta = $rideTrack['eta']['minutes'] ?? $eta;
        }

        return [
            'job_id' => $job->id,
            'status' => $job->status->value,
            'eta_minutes' => $eta,
            'destination' => [
                'address' => $job->address,
                'latitude' => $job->latitude,
                'longitude' => $job->longitude,
            ],
            'artisan' => $job->artisan ? [
                'id' => $job->artisan->id,
                'name' => $job->artisan->name,
                'phone' => $job->artisan->phone,
                'position' => $artisanLive,
            ] : null,
            'ride' => $rideTrack,
            'server_time' => now()->toIso8601String(),
        ];
    }

    private function transition(Job $job, User $artisan, JobStatus $from, JobStatus $to, array $extra): Job
    {
        $job = Job::whereKey($job->id)->firstOrFail();
        if ($job->status !== $from) {
            throw new ApiException('Cette action n’est pas possible maintenant.', 409, 'job_bad_status');
        }
        $this->assertOwner($job, artisan: $artisan);
        $job->forceFill(['status' => $to, ...$extra])->save();

        return $job->fresh(['artisan.profile', 'client', 'ride.driver.profile']);
    }

    private function assertOwner(Job $job, ?User $artisan = null, ?User $client = null): void
    {
        if ($artisan && $job->artisan_id !== $artisan->id) {
            throw new ApiException('Intervention introuvable.', 404, 'job_not_found');
        }
        if ($client && $job->client_id !== $client->id) {
            throw new ApiException('Intervention introuvable.', 404, 'job_not_found');
        }
    }

    private function assertNoActive(User $user, string $column, string $message): void
    {
        $exists = Job::where($column, $user->id)->whereIn('status', JobStatus::active())->exists();
        if ($exists) {
            throw new ApiException($message, 409, 'job_already_active');
        }
    }

    private function assertArtisanReady(User $artisan, string $trade): void
    {
        if ($artisan->role !== UserRole::Artisan) {
            throw new ApiException('Compte artisan requis.', 403, 'forbidden_role');
        }
        if (! $artisan->is_available) {
            throw new ApiException('Passez en service pour recevoir des demandes.', 409, 'artisan_offline');
        }
        $trades = $artisan->profile?->specialties ?? [];
        if (! in_array($trade, $trades, true)) {
            throw new ApiException('Ce métier ne fait pas partie de vos spécialités.', 409, 'trade_mismatch');
        }

        if (Job::where('artisan_id', $artisan->id)->whereIn('status', JobStatus::underway())->exists()) {
            throw new ApiException('Vous avez déjà une intervention en cours.', 409, 'job_already_active');
        }
    }

    /**
     * GPS emulatrice (ex. Mountain View) trop loin du client : on reprend
     * la derniere position connue de l'artisan si elle est plus proche de la mission.
     *
     * @return array{0: float, 1: float}
     */
    private function pickupNearJob(User $artisan, Job $job, float $lat, float $lng): array
    {
        $max = (float) config('job.radius_km', 15) * 3;
        $fromGps = Geo::km($lat, $lng, $job->latitude, $job->longitude);
        if ($fromGps <= $max || ! $artisan->hasLocation()) {
            return [$lat, $lng];
        }

        $fromSaved = Geo::km($artisan->latitude, $artisan->longitude, $job->latitude, $job->longitude);
        if ($fromSaved < $fromGps) {
            return [(float) $artisan->latitude, (float) $artisan->longitude];
        }

        return [$lat, $lng];
    }
}
