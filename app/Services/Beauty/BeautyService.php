<?php

namespace App\Services\Beauty;

use App\Enums\BeautyStatus;
use App\Enums\JobOfferStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\BeautyOffer;
use App\Models\BeautyRequest;
use App\Models\User;
use App\Services\Notifications\NearbyProviders;
use App\Support\Geo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BeautyService
{
    public function __construct(private readonly NearbyProviders $nearby) {}

    public function publish(User $client, array $data): BeautyRequest
    {
        $request = BeautyRequest::create([
            'client_id' => $client->id,
            'specialty' => $data['specialty'],
            'mode' => $data['mode'] ?? 'salon',
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => BeautyStatus::Searching,
        ]);

        // Pros de la prestation les plus proches (demande sans position : aucune notification,
        // elle reste visible dans la liste par specialite comme avant).
        if ($request->hasLocation()) {
            $this->nearby->notify(
                UserRole::Beaute,
                $request->latitude,
                $request->longitude,
                'Nouvelle demande beauté',
                'Un client près de vous cherche un professionnel.',
                ['type' => 'beauty_request', 'beauty_request_id' => $request->id],
                fn (User $pro) => in_array($request->specialty->value, $this->specialtiesOf($pro), true),
            );
        }

        return $request;
    }

    /**
     * Demandes ouvertes proposees a un pro : sa specialite, et dans son rayon quand la
     * demande a une position (il lui faut alors une position recente).
     *
     * @return Collection<int, BeautyRequest>
     */
    public function availableFor(User $pro, int $limit = 30): Collection
    {
        return BeautyRequest::with(['client', 'offers.pro'])
            ->where('status', BeautyStatus::Searching)
            ->whereIn('specialty', $this->specialtiesOf($pro))
            ->latest('id')
            ->limit(200)
            ->get()
            ->filter(fn (BeautyRequest $request) => $this->reaches($pro, $request))
            // Le client le plus proche d'abord ; les demandes sans position (anciennes versions) a la fin.
            ->sortBy(fn (BeautyRequest $request) => $request->hasLocation() && $pro->hasFreshLocation()
                ? Geo::km($pro->latitude, $pro->longitude, $request->latitude, $request->longitude)
                : INF)
            ->take($limit)
            ->values();
    }

    private function reaches(User $pro, BeautyRequest $request): bool
    {
        return ! $request->hasLocation()
            || Geo::inServiceArea($pro, $request->latitude, $request->longitude);
    }

    public function offer(User $pro, BeautyRequest $request, string $message): BeautyOffer
    {
        $this->assertCovers($pro, $request);

        return DB::transaction(function () use ($pro, $request, $message) {
            $request = BeautyRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== BeautyStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'beauty_not_searching');
            }

            $existing = BeautyOffer::where('beauty_request_id', $request->id)
                ->where('pro_id', $pro->id)
                ->first();
            if ($existing && $existing->status === JobOfferStatus::Pending) {
                $existing->forceFill(['message' => $message])->save();

                return $existing->fresh('pro');
            }
            if ($existing) {
                throw new ApiException('Vous ne pouvez plus répondre à cette demande.', 409, 'offer_closed');
            }

            return BeautyOffer::create([
                'beauty_request_id' => $request->id,
                'pro_id' => $pro->id,
                'message' => $message,
                'status' => JobOfferStatus::Pending,
            ])->fresh('pro');
        });
    }

    public function choose(User $client, BeautyRequest $request, int $offerId): BeautyRequest
    {
        return DB::transaction(function () use ($client, $request, $offerId) {
            $request = BeautyRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->client_id !== $client->id) {
                throw new ApiException('Demande introuvable.', 404, 'beauty_not_found');
            }
            if ($request->status !== BeautyStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'beauty_not_searching');
            }

            $offer = BeautyOffer::where('beauty_request_id', $request->id)->whereKey($offerId)->lockForUpdate()->firstOrFail();
            if ($offer->status !== JobOfferStatus::Pending) {
                throw new ApiException('Cette réponse n’est plus valable.', 409, 'offer_not_pending');
            }

            $request->forceFill([
                'pro_id' => $offer->pro_id,
                'status' => BeautyStatus::Assigned,
                'assigned_at' => now(),
            ])->save();
            $offer->forceFill(['status' => JobOfferStatus::Chosen])->save();
            BeautyOffer::where('beauty_request_id', $request->id)
                ->where('id', '!=', $offer->id)
                ->where('status', JobOfferStatus::Pending)
                ->update(['status' => JobOfferStatus::Declined->value]);

            return $request->fresh(['client', 'pro', 'offers.pro']);
        });
    }

    public function complete(User $actor, BeautyRequest $request): BeautyRequest
    {
        if (! in_array($actor->id, [$request->client_id, $request->pro_id], true)) {
            throw new ApiException('Demande introuvable.', 404, 'beauty_not_found');
        }
        if ($request->status !== BeautyStatus::Assigned) {
            throw new ApiException('Le rendez-vous doit d’abord être attribué.', 409, 'beauty_bad_status');
        }
        $request->forceFill(['status' => BeautyStatus::Completed, 'completed_at' => now()])->save();

        return $request->fresh(['client', 'pro', 'offers.pro']);
    }

    public function cancel(User $actor, BeautyRequest $request, ?string $reason = null): BeautyRequest
    {
        if (! in_array($actor->id, [$request->client_id, $request->pro_id], true)) {
            throw new ApiException('Demande introuvable.', 404, 'beauty_not_found');
        }
        if ($request->status->isFinal()) {
            throw new ApiException('Cette demande est déjà close.', 409, 'beauty_closed');
        }
        $request->forceFill([
            'status' => BeautyStatus::Cancelled,
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ])->save();

        return $request->fresh(['client', 'pro', 'offers.pro']);
    }

    /** @return list<string> */
    public function specialtiesOf(User $pro): array
    {
        $pro->loadMissing('profile');

        return array_values($pro->profileForRole(UserRole::Beaute)->first()?->specialties ?? []);
    }

    private function assertCovers(User $pro, BeautyRequest $request): void
    {
        if (! $pro->hasRole(UserRole::Beaute)) {
            throw new ApiException('Compte Soins et beauté requis.', 403, 'forbidden_role');
        }
        $pro->ensureVisible();
        if (! $pro->is_available) {
            throw new ApiException('Passez en service pour répondre.', 409, 'beaute_offline');
        }
        if (! in_array($request->specialty->value, $this->specialtiesOf($pro), true)) {
            throw new ApiException('Cette prestation n’est pas la vôtre.', 403, 'specialty_not_covered');
        }
        if ($request->hasLocation() && ! $pro->hasFreshLocation()) {
            throw new ApiException('Activez le partage de position pour répondre.', 409, 'location_required');
        }
        if (! $this->reaches($pro, $request)) {
            throw new ApiException('Cette demande est hors de votre zone de service.', 403, 'beauty_outside_service_area');
        }
    }
}
