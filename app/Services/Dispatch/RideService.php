<?php

namespace App\Services\Dispatch;

use App\Enums\JobStatus;
use App\Enums\RideStatus;
use App\Enums\TransferStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\ArtisanDriver;
use App\Models\Job;
use App\Models\MoneyTransfer;
use App\Models\Ride;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

class RideService
{
    public function __construct(private readonly LocationPing $ping) {}

    public function request(User $passenger, array $data): Ride
    {
        if (Ride::where('passenger_id', $passenger->id)->whereIn('status', RideStatus::active())->exists()) {
            throw new ApiException('Vous avez déjà une course en cours.', 409, 'ride_already_active');
        }

        $km = Geo::km(
            $data['pickup_latitude'], $data['pickup_longitude'],
            $data['dropoff_latitude'], $data['dropoff_longitude'],
        );

        $offer = $this->resolveOffer($passenger, $data['driver_id'] ?? null);

        return Ride::create([
            'passenger_id' => $passenger->id,
            'job_id' => $data['job_id'] ?? null,
            'transfer_id' => $data['transfer_id'] ?? null,
            'status' => RideStatus::Searching,
            'pickup_address' => $data['pickup_address'],
            'pickup_latitude' => $data['pickup_latitude'],
            'pickup_longitude' => $data['pickup_longitude'],
            'dropoff_address' => $data['dropoff_address'],
            'dropoff_latitude' => $data['dropoff_latitude'],
            'dropoff_longitude' => $data['dropoff_longitude'],
            'distance_km' => round($km, 2),
            'price_cents' => Geo::ridePriceCents($km),
            'eta_minutes' => Geo::etaMinutes($km),
            'offered_driver_id' => $offer['driver_id'],
            'offer_expires_at' => $offer['expires_at'],
        ]);
    }

    /** Recible une course encore en recherche : un habituel, ou tout le monde. */
    public function retarget(User $passenger, Ride $ride, ?int $driverId): Ride
    {
        if ($ride->passenger_id !== $passenger->id) {
            throw new ApiException('Course introuvable.', 404, 'ride_not_found');
        }
        if ($ride->status !== RideStatus::Searching) {
            throw new ApiException('Cette course n’est plus en recherche.', 409, 'ride_not_searching');
        }

        $offer = $this->resolveOffer($passenger, $driverId);
        $ride->forceFill([
            'offered_driver_id' => $offer['driver_id'],
            'offer_expires_at' => $offer['expires_at'],
        ])->save();

        return $ride->fresh(['passenger', 'offeredDriver.profile', 'job']);
    }

    public function accept(User $driver, Ride $ride): Ride
    {
        $this->assertDriverReady($driver);

        return DB::transaction(function () use ($driver, $ride) {
            $ride = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
            if ($ride->status !== RideStatus::Searching) {
                throw new ApiException('Cette course n’est plus disponible.', 409, 'ride_not_searching');
            }
            if ($ride->hasActiveOffer() && $ride->offered_driver_id !== $driver->id) {
                throw new ApiException('Cette course est réservée à un autre transporteur.', 409, 'ride_reserved');
            }
            if (Ride::where('driver_id', $driver->id)->whereIn('status', RideStatus::active())->exists()) {
                throw new ApiException('Vous avez déjà une course en cours.', 409, 'ride_already_active');
            }

            $ride->forceFill([
                'driver_id' => $driver->id,
                'status' => RideStatus::Accepted,
                'accepted_at' => now(),
                'offered_driver_id' => null,
                'offer_expires_at' => null,
            ])->save();

            return $ride->fresh(['passenger', 'driver.profile', 'job']);
        });
    }

    public function pickup(User $driver, Ride $ride): Ride
    {
        $ride = $this->ownedByDriver($ride, $driver);
        if ($ride->status !== RideStatus::Accepted) {
            throw new ApiException('Prenez d’abord la course, puis marquez la prise en charge.', 409, 'ride_bad_status');
        }

        $ride->forceFill(['status' => RideStatus::InProgress, 'picked_up_at' => now()])->save();

        if ($ride->job_id) {
            $job = Job::find($ride->job_id);
            if ($job && $job->status === JobStatus::Assigned) {
                $job->forceFill(['status' => JobStatus::EnRoute, 'en_route_at' => now()])->save();
            }
        }

        if ($ride->transfer_id) {
            $transfer = MoneyTransfer::find($ride->transfer_id);
            if ($transfer && $transfer->status === TransferStatus::Assigned) {
                $transfer->forceFill(['status' => TransferStatus::Meeting, 'meeting_at' => now()])->save();
            }
        }

        return $ride->fresh(['passenger', 'driver.profile', 'job']);
    }

    public function complete(User $driver, Ride $ride): Ride
    {
        $ride = $this->ownedByDriver($ride, $driver);
        if ($ride->status !== RideStatus::InProgress) {
            throw new ApiException('La course n’a pas encore commencé.', 409, 'ride_bad_status');
        }

        $ride->forceFill(['status' => RideStatus::Completed, 'completed_at' => now()])->save();
        $this->ping->forget($driver->id);

        if ($ride->job_id) {
            $job = Job::find($ride->job_id);
            if ($job && in_array($job->status, [JobStatus::Assigned, JobStatus::EnRoute], true)) {
                $job->forceFill([
                    'status' => JobStatus::Arrived,
                    'arrived_at' => now(),
                    'en_route_at' => $job->en_route_at ?? now(),
                ])->save();
            }
        }

        if ($ride->transfer_id) {
            $transfer = MoneyTransfer::find($ride->transfer_id);
            if ($transfer && $transfer->status === TransferStatus::Assigned) {
                $transfer->forceFill(['status' => TransferStatus::Meeting, 'meeting_at' => now()])->save();
            }
        }

        return $ride->fresh(['passenger', 'driver.profile', 'job']);
    }

    public function decline(User $driver, Ride $ride): Ride
    {
        return DB::transaction(function () use ($driver, $ride) {
            $ride = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
            if ($ride->status !== RideStatus::Searching || $ride->offered_driver_id !== $driver->id) {
                throw new ApiException('Cette proposition ne vous est plus destinée.', 409, 'ride_not_offered');
            }

            $ride->forceFill(['offered_driver_id' => null, 'offer_expires_at' => null])->save();

            return $ride->fresh(['passenger', 'job']);
        });
    }

    public function cancel(User $actor, Ride $ride, ?string $reason = null): Ride
    {
        return DB::transaction(function () use ($actor, $ride, $reason) {
            $ride = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
            $asPassenger = $ride->passenger_id === $actor->id;
            $asDriver = $ride->driver_id === $actor->id;
            if (! $asPassenger && ! $asDriver) {
                throw new ApiException('Course introuvable.', 404, 'ride_not_found');
            }
            if ($ride->status->isFinal()) {
                throw new ApiException('Cette course est déjà close.', 409, 'ride_closed');
            }
            if ($ride->status === RideStatus::InProgress && $asPassenger) {
                throw new ApiException('La course a déjà commencé.', 409, 'ride_started');
            }

            $this->cancelLocked($ride, $reason);

            return $ride->fresh(['passenger', 'driver.profile']);
        });
    }

    public function cancelLocked(Ride $ride, ?string $reason = null): void
    {
        $ride->forceFill([
            'status' => RideStatus::Cancelled,
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ])->save();

        if ($ride->driver_id) {
            $this->ping->forget($ride->driver_id);
        }
    }

    /** @return array<string, mixed> */
    public function tracking(Ride $ride): array
    {
        $driverLive = $ride->driver_id ? $this->ping->live($ride->driver_id) : null;
        $phase = match ($ride->status) {
            RideStatus::Searching => 'searching',
            RideStatus::Accepted => 'to_pickup',
            RideStatus::InProgress => 'to_dropoff',
            RideStatus::Completed => 'done',
            RideStatus::Cancelled => 'cancelled',
        };

        $pickup = ['latitude' => $ride->pickup_latitude, 'longitude' => $ride->pickup_longitude];
        $dropoff = ['latitude' => $ride->dropoff_latitude, 'longitude' => $ride->dropoff_longitude];
        $eta = null;
        if ($driverLive && ! $driverLive['stale']) {
            $target = $phase === 'to_pickup' ? $pickup : ($phase === 'to_dropoff' ? $dropoff : null);
            if ($target) {
                $km = Geo::km($driverLive['latitude'], $driverLive['longitude'], $target['latitude'], $target['longitude']);
                $eta = [
                    'minutes' => Geo::etaMinutes($km),
                    'distance_km' => round($km, 2),
                    'to' => $phase === 'to_pickup' ? 'pickup' : 'dropoff',
                ];
            }
        }

        return [
            'ride_id' => $ride->id,
            'status' => $ride->status->value,
            'phase' => $phase,
            'price_cents' => $ride->price_cents,
            'distance_km' => $ride->distance_km,
            'driver' => $ride->driver ? [
                'id' => $ride->driver->id,
                'name' => $ride->driver->name,
                'phone' => $ride->driver->phone,
                'vehicle' => $ride->driver->profile?->vehicle_model,
                'plate' => $ride->driver->profile?->vehicle_plate,
            ] : null,
            'courier' => $driverLive,
            'pickup' => [
                'address' => $ride->pickup_address,
                'latitude' => $ride->pickup_latitude,
                'longitude' => $ride->pickup_longitude,
            ],
            'dropoff' => [
                'address' => $ride->dropoff_address,
                'latitude' => $ride->dropoff_latitude,
                'longitude' => $ride->dropoff_longitude,
            ],
            'eta' => $eta,
            'server_time' => now()->toIso8601String(),
        ];
    }

    /** @return array{driver_id: int|null, expires_at: \Illuminate\Support\Carbon|null} */
    private function resolveOffer(User $passenger, mixed $driverId): array
    {
        if ($driverId === null || $driverId === '') {
            return ['driver_id' => null, 'expires_at' => null];
        }

        $driverId = (int) $driverId;
        if (! ArtisanDriver::where('artisan_id', $passenger->id)->where('driver_id', $driverId)->exists()) {
            throw new ApiException(
                'Enregistrez ce transporteur parmi vos habituels avant de l’appeler.',
                422,
                'driver_not_trusted',
            );
        }

        $driver = User::where('role', UserRole::Driver)->where('status', UserStatus::Active)->find($driverId);
        if (! $driver) {
            throw new ApiException('Transporteur introuvable.', 422, 'driver_not_found');
        }
        if (! $driver->is_available) {
            throw new ApiException('Ce transporteur n’est pas en service pour le moment.', 409, 'driver_offline');
        }

        $minutes = max(1, (int) config('job.direct_offer_minutes', 10));

        return ['driver_id' => $driver->id, 'expires_at' => now()->addMinutes($minutes)];
    }

    private function ownedByDriver(Ride $ride, User $driver): Ride
    {
        $ride = Ride::whereKey($ride->id)->firstOrFail();
        if ($ride->driver_id !== $driver->id) {
            throw new ApiException('Course introuvable.', 404, 'ride_not_found');
        }

        return $ride;
    }

    private function assertDriverReady(User $driver): void
    {
        if ($driver->role !== UserRole::Driver) {
            throw new ApiException('Compte transporteur requis.', 403, 'forbidden_role');
        }
        if (! $driver->is_available) {
            throw new ApiException('Passez en service pour prendre une course.', 409, 'driver_offline');
        }
    }
}
